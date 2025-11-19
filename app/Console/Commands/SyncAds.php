<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DomCrawler\Crawler;
use App\Models\AdNotification;
use App\Models\AdNotificationFilter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SyncAds extends Command
{
    protected $signature = 'ss:sync-ads {--test : Run test query}';
    protected $description = 'Sync ads from ss.com for active notifications';

    private $baseUrl = 'https://www.ss.com';
    private $client;

    public function __construct()
    {
        parent::__construct();
        
        $this->client = new Client([
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
            ]
        ]);
    }

    public function handle()
    {
        if ($this->option('test')) {
            return $this->testQuery();
        }

        $this->info('Starting ad sync for active notifications...');

        $notifications = AdNotification::where('active', true)
            ->with(['category', 'user', 'filters.filter', 'filters.filterValue'])
            ->get();

        if ($notifications->isEmpty()) {
            $this->info('No active notifications found.');
            return 0;
        }

        $this->info("Found {$notifications->count()} active notification(s).");

        foreach ($notifications as $notification) {
            try {
                $this->processNotification($notification);
            } catch (\Exception $e) {
                $this->error("Error processing notification ID {$notification->id}: " . $e->getMessage());
                Log::error("SyncAds: Error processing notification {$notification->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        $this->info('Ad sync completed.');
        return 0;
    }


    private function processNotification(AdNotification $notification)
    {
        $this->info("Processing notification: {$notification->name} (ID: {$notification->id})");

        $category = $notification->category;
        if (!$category) {
            $this->warn("Notification {$notification->id} has no category. Skipping.");
            return;
        }

        $urlPathParts = [];
        $formParams = [];
        $rangeFilters = []; // Group range filters by form_param

        foreach ($notification->filters as $notificationFilter) {
            $filter = $notificationFilter->filter;
            if (!$filter) {
                continue;
            }

            $formParam = $filter->form_param;

            // If form_param is 'sid' or null, add to URL path
            if ($formParam === 'sid' || $formParam === null) {
                $pathPart = $this->extractUrlPathPart($notificationFilter);
                if ($pathPart) {
                    $urlPathParts[] = $pathPart;
                }
            } else {
                // For range filters, group them together
                if ($notificationFilter->is_min !== null) {
                    if (!isset($rangeFilters[$formParam])) {
                        $rangeFilters[$formParam] = ['min' => null, 'max' => null];
                    }
                    if ($notificationFilter->is_min) {
                        $rangeFilters[$formParam]['min'] = $notificationFilter->value;
                    } else {
                        $rangeFilters[$formParam]['max'] = $notificationFilter->value;
                    }
                } else {
                    // Single value filter
                    $formParams[$formParam] = $notificationFilter->value;
                }
            }
        }

        // Add grouped range filters to formParams
        foreach ($rangeFilters as $formParam => $range) {
            if ($range['min'] !== null) {
                // Convert to int for price filters (topt[8])
                if ($formParam === 'topt[8]') {
                    $formParams[$formParam . '[min]'] = $range['min'];
                } else {
                    $formParams[$formParam . '[min]'] = $range['min'];
                }
            }
            if ($range['max'] !== null) {
                // Convert to int for price filters (topt[8])
                if ($formParam === 'topt[8]') {
                    $formParams[$formParam . '[max]'] = $range['max'];
                } else {
                    $formParams[$formParam . '[max]'] = $range['max'];
                }
            }
        }
        
        // Convert opt[34] and opt[35] to integers
        if (isset($formParams['opt[34]'])) {
            $formParams['opt[34]'] = $formParams['opt[34]'];
        }
        if (isset($formParams['opt[35]'])) {
            $formParams['opt[35]'] = $formParams['opt[35]'];
        }

        // Build the base URL (category URL)
        $baseUrlPath = rtrim($category->url, '/');
        
        // Separate transaction types from model numbers
        $transactionTypes = [];
        $modelParts = [];
        foreach ($urlPathParts as $part) {
            if (in_array($part, ['sell', 'buy', 'change'])) {
                $transactionTypes[] = $part;
            } else {
                $modelParts[] = $part;
            }
        }
        
        // Build URL: base + model parts (transaction types will be added after filter/)
        // Example: /lv/transport/cars/audi/80 (not /lv/transport/cars/audi/sell/80)
        $urlPath = $baseUrlPath;
        if (!empty($modelParts)) {
            $urlPath .= '/' . implode('/', $modelParts);
        }
        
        $this->info("Built URL (for initial GET): {$urlPath}");
        $this->info("Transaction types: " . json_encode($transactionTypes));
        $this->info("Form params: " . json_encode($formParams));

        $pageResponse = $this->executeQuery($urlPath, $formParams, $notification, array_merge($modelParts, $transactionTypes));
        
        if ($pageResponse) {
            $this->parseAndCheckAds($pageResponse, $notification, $urlPath);
        }
    }

    private function extractUrlPathPart(AdNotificationFilter $notificationFilter): ?string
    {
        if ($notificationFilter->filter_value_id && $notificationFilter->filterValue) {
            $value = $notificationFilter->filterValue->value;
        } else {
            $value = $notificationFilter->value;
        }

        if (empty($value)) {
            return null;
        }

        $value = trim($value, '/');
        $parts = explode('/', $value);
        $lastPart = end($parts);
        return !empty($lastPart) ? $lastPart : null;
    }

    /**
     * Get common headers that match browser behavior
     */
    private function getCommonHeaders(string $refererUrl): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Cache-Control' => 'max-age=0',
            'Referer' => $refererUrl,
            'Sec-CH-UA' => '"Google Chrome";v="141", "Not?A_Brand";v="8", "Chromium";v="141"',
            'Sec-CH-UA-Mobile' => '?0',
            'Sec-CH-UA-Platform' => '"Linux"',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'same-origin',
            'Sec-Fetch-User' => '?1',
            'Upgrade-Insecure-Requests' => '1',
        ];
    }

    /**
     * Test method that replicates the exact curl commands for BMW
     * Tests both Guzzle and direct curl to compare
     */
    private function testQuery()
    {
        $this->info('Running test query to replicate BMW curl commands EXACTLY...');
        
        $url = 'https://www.ss.com/lv/transport/cars/bmw/filter/';
        
        // Use the EXACT data from curl --data-raw (already URL encoded)
        // topt[15][min]=6.0 (filtering for engine displacement >= 6.0)
        $postData = 'topt%5B8%5D%5Bmin%5D=&topt%5B8%5D%5Bmax%5D=&topt%5B18%5D%5Bmin%5D=&topt%5B18%5D%5Bmax%5D=&topt%5B15%5D%5Bmin%5D=6.0&topt%5B15%5D%5Bmax%5D=&opt%5B34%5D=&opt%5B35%5D=&opt%5B32%5D=&opt%5B17%5D=&sid=%2Flv%2Ftransport%2Fcars%2Fbmw%2Ffilter%2F';
        
        $this->info("Test URL: {$url}");
        $this->info("POST Data (exact from curl): {$postData}");
        
        // First, try using curl directly via exec to see if it works
        $this->info("\n=== Testing with direct curl command ===");
        $curlCommand = sprintf(
            "curl -s -L '%s' " .
            "-H 'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7' " .
            "-H 'accept-language: en-US,en;q=0.9' " .
            "-H 'cache-control: max-age=0' " .
            "-H 'content-type: application/x-www-form-urlencoded' " .
            "-b 'PHPSESSID=ae7d20eccfd19987f69248e06bf69cdf; LG=lv; _ga=GA1.1.450222064.1763515493; sid_c=1; sid=2476b762cc8251c397e7140105fae61419fde4958c4905a5e6bc3d337f25322a67b10a855dbd97a0c8147975465cd8c0; _ga_1XPPRS4B93=GS2.1.s1763515493\$o1\$g1\$t1763518231\$j55\$l0\$h776191415' " .
            "-H 'origin: https://www.ss.com' " .
            "-H 'priority: u=0, i' " .
            "-H 'referer: https://www.ss.com/lv/transport/cars/bmw/filter/' " .
            "-H 'sec-ch-ua: \"Google Chrome\";v=\"141\", \"Not?A_Brand\";v=\"8\", \"Chromium\";v=\"141\"' " .
            "-H 'sec-ch-ua-mobile: ?0' " .
            "-H 'sec-ch-ua-platform: \"Linux\"' " .
            "-H 'sec-fetch-dest: document' " .
            "-H 'sec-fetch-mode: navigate' " .
            "-H 'sec-fetch-site: same-origin' " .
            "-H 'sec-fetch-user: ?1' " .
            "-H 'upgrade-insecure-requests: 1' " .
            "-H 'user-agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' " .
            "--data-raw '%s'",
            $url,
            $postData
        );
        
        $this->info("Running curl command...");
        exec($curlCommand . ' 2>&1', $curlOutput, $curlReturnCode);
        $curlHtml = implode("\n", $curlOutput);
        
        if ($curlReturnCode === 0 && !empty($curlHtml)) {
            $this->info("✓ Curl command succeeded! Response length: " . strlen($curlHtml) . " bytes");
            $curlDebugFile = storage_path('logs/test_curl_direct_' . time() . '.html');
            file_put_contents($curlDebugFile, $curlHtml);
            $this->info("Curl HTML saved to: {$curlDebugFile}");
            
            // Check curl response
            $curlCrawler = new Crawler($curlHtml);
            $curlAdLinks = $curlCrawler->filter('a[href^="/msg/"]');
            $curlAdCount = $curlAdLinks->count();
            $this->info("Curl found {$curlAdCount} ad links");
            
            // Check if filter was applied in curl response
            $curlFilterApplied = false;
            try {
                $curlFilterElement = $curlCrawler->filter('#f_o_15_min');
                if ($curlFilterElement->count() > 0) {
                    $tagName = $curlFilterElement->nodeName();
                    if ($tagName === 'select') {
                        $selectedOption = $curlFilterElement->filter('option[selected]');
                        if ($selectedOption->count() > 0) {
                            $selectedValue = $selectedOption->attr('value');
                            $this->info("Curl filter form selected value: {$selectedValue}");
                            $curlFilterApplied = ($selectedValue === '6.0' || $selectedValue === '6');
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore
            }
            
            if ($curlAdCount > 0) {
                $firstCurlAd = $curlAdLinks->first();
                $firstCurlAdUrl = $firstCurlAd->attr('href');
                $this->info("First curl ad URL: {$firstCurlAdUrl}");
                
                if ($curlFilterApplied) {
                    $this->info("✓ Curl: Filter was applied! (6.0 selected in form)");
                } else {
                    $this->warn("⚠ Curl: Filter might not have been applied (6.0 not selected)");
                }
            }
        } else {
            $this->warn("⚠ Curl command failed or returned empty. Return code: {$curlReturnCode}");
        }
        
        // Now try with Guzzle
        $this->info("\n=== Testing with Guzzle ===");
        
        // Create a cookie jar and set the EXACT cookies from curl
        $cookieJar = new CookieJar();
        $cookies = [
            new SetCookie(['Name' => 'PHPSESSID', 'Value' => 'ae7d20eccfd19987f69248e06bf69cdf', 'Domain' => 'www.ss.com']),
            new SetCookie(['Name' => 'LG', 'Value' => 'lv', 'Domain' => 'www.ss.com']),
            new SetCookie(['Name' => '_ga', 'Value' => 'GA1.1.450222064.1763515493', 'Domain' => 'www.ss.com']),
            new SetCookie(['Name' => 'sid_c', 'Value' => '1', 'Domain' => 'www.ss.com']),
            new SetCookie(['Name' => 'sid', 'Value' => '2476b762cc8251c397e7140105fae61419fde4958c4905a5e6bc3d337f25322a67b10a855dbd97a0c8147975465cd8c0', 'Domain' => 'www.ss.com']),
            new SetCookie(['Name' => '_ga_1XPPRS4B93', 'Value' => 'GS2.1.s1763515493$o1$g1$t1763518231$j55$l0$h776191415', 'Domain' => 'www.ss.com']),
        ];
        foreach ($cookies as $cookie) {
            $cookieJar->setCookie($cookie);
        }
        
        // Headers matching curl EXACTLY
        $postHeaders = [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Cache-Control' => 'max-age=0',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Origin' => 'https://www.ss.com',
            'Priority' => 'u=0, i',
            'Referer' => 'https://www.ss.com/lv/transport/cars/bmw/filter/',
            'Sec-CH-UA' => '"Google Chrome";v="141", "Not?A_Brand";v="8", "Chromium";v="141"',
            'Sec-CH-UA-Mobile' => '?0',
            'Sec-CH-UA-Platform' => '"Linux"',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'same-origin',
            'Sec-Fetch-User' => '?1',
            'Upgrade-Insecure-Requests' => '1',
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
        ];
        
        try {
            // Make POST request exactly as curl does (no initial GET)
            $this->info("POST URL: {$url}");
            
            // Don't follow redirects automatically - handle manually
            $postResponse = $this->client->post($url, [
                'cookies' => $cookieJar,
                'body' => $postData,
                'headers' => $postHeaders,
                'allow_redirects' => false,
            ]);
            
            $this->info("POST Response Status: " . $postResponse->getStatusCode());
            
            // Get cookies after POST
            $cookies = [];
            foreach ($cookieJar->toArray() as $cookie) {
                $cookies[$cookie['Name']] = $cookie['Value'];
            }
            $this->info("Cookies after POST: " . json_encode($cookies, JSON_PRETTY_PRINT));
            
            // POST should return 302 - follow it manually
            if ($postResponse->getStatusCode() >= 300 && $postResponse->getStatusCode() < 400) {
                $location = $postResponse->getHeaderLine('Location');
                $this->info("POST redirected to: {$location}");
                
                // If relative URL, make it absolute
                if ($location && strpos($location, 'http') !== 0) {
                    if (strpos($location, '/') === 0) {
                        $redirectUrl = $this->baseUrl . $location;
                    } else {
                        $redirectUrl = $url . '/' . ltrim($location, '/');
                    }
                } else {
                    $redirectUrl = $location ?: $url;
                }
                
                $this->info("Making GET request to redirect location: {$redirectUrl}");
                
                // Make GET request to the redirect location (like Postman does)
                $getHeaders = [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Cache-Control' => 'max-age=0',
                    'Referer' => $url,
                    'Sec-CH-UA' => '"Google Chrome";v="141", "Not?A_Brand";v="8", "Chromium";v="141"',
                    'Sec-CH-UA-Mobile' => '?0',
                    'Sec-CH-UA-Platform' => '"Linux"',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'same-origin',
                    'Sec-Fetch-User' => '?1',
                    'Upgrade-Insecure-Requests' => '1',
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
                ];
                
                $finalResponse = $this->client->get($redirectUrl, [
                    'cookies' => $cookieJar,
                    'headers' => $getHeaders,
                    'allow_redirects' => true,
                ]);
                
                $this->info("GET Response Status: " . $finalResponse->getStatusCode());
                $html = $finalResponse->getBody()->getContents();
            } else {
                // If no redirect, use POST response directly
                $html = $postResponse->getBody()->getContents();
            }
            $this->info("Guzzle response body length: " . strlen($html) . " bytes");
            
            // Save HTML for debugging
            $debugFile = storage_path('logs/test_guzzle_' . time() . '.html');
            file_put_contents($debugFile, $html);
            $this->info("Guzzle HTML saved to: {$debugFile}");
            
            // Check if the filter form shows our filter values
            $crawler = new Crawler($html);
            
            // Try to find ads in the response
            $adLinks = $crawler->filter('a[href^="/msg/"]');
            $adCount = $adLinks->count();
            $this->info("Guzzle found {$adCount} ad links in response");
            
            // Check if filter was applied by checking the form field
            $filterApplied = false;
            try {
                $filterElement = $crawler->filter('#f_o_15_min');
                if ($filterElement->count() > 0) {
                    $tagName = $filterElement->nodeName();
                    if ($tagName === 'select') {
                        $selectedOption = $filterElement->filter('option[selected]');
                        if ($selectedOption->count() > 0) {
                            $selectedValue = $selectedOption->attr('value');
                            $this->info("Filter form selected value: {$selectedValue}");
                            $filterApplied = ($selectedValue === '6.0' || $selectedValue === '6');
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->warn("Error checking filter: " . $e->getMessage());
            }
            
            if ($adCount > 0) {
                $firstAd = $adLinks->first();
                $firstAdUrl = $firstAd->attr('href');
                $adId = $this->extractAdIdFromUrl($firstAdUrl);
                $this->info("First Guzzle ad URL: {$firstAdUrl}");
                $this->info("First Guzzle ad ID: {$adId}");
                
                if ($filterApplied) {
                    $this->info("✓ Filter was applied! (6.0 selected in form)");
                } else {
                    $this->warn("⚠ Filter might not have been applied (6.0 not selected)");
                }
            }
            
            // Compare results
            if (isset($curlHtml) && !empty($curlHtml)) {
                $this->info("\n=== Comparison ===");
                $this->info("Curl response length: " . strlen($curlHtml) . " bytes");
                $this->info("Guzzle response length: " . strlen($html) . " bytes");
                $this->info("Responses match: " . ($curlHtml === $html ? 'YES' : 'NO'));
                
                if ($curlHtml !== $html) {
                    $this->warn("⚠ Responses differ! Check the saved HTML files to compare.");
                }
            }
            
            return 0;
            
        } catch (RequestException $e) {
            $this->error("Request failed: " . $e->getMessage());
            if ($e->hasResponse()) {
                $this->error("Response status: " . $e->getResponse()->getStatusCode());
                $this->error("Response body: " . substr($e->getResponse()->getBody()->getContents(), 0, 500));
            }
            return 1;
        } catch (\Exception $e) {
            $this->error("Unexpected error: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
            return 1;
        }
    }

    private function executeQuery(string $urlPath, array $formParams, AdNotification $notification, array $urlPathParts = [])
    {
        try {
            // Build the final URL - add /filter/ if there are form params
            // Ensure urlPath is relative (strip base URL if present)
            $relativeUrlPath = $urlPath;
            if (strpos($relativeUrlPath, $this->baseUrl) === 0) {
                $relativeUrlPath = substr($relativeUrlPath, strlen($this->baseUrl));
            }
            $relativeUrlPath = rtrim($relativeUrlPath, '/');
            
            $finalUrlPath = $relativeUrlPath;
            if (!empty($formParams)) {
                $finalUrlPath .= '/filter/';
            }
            
            // Use absolute URL for requests
            $fullUrl = $this->baseUrl . $finalUrlPath;
            $this->info("Final URL: {$fullUrl}");
            $this->info("Final URL Path (for sid param): {$finalUrlPath}");

            // Create a cookie jar
            $cookieJar = new CookieJar();
            
            // Try to get fresh cookies from the server first
            // Visit homepage once to get PHPSESSID
            // Note: 'sid' cookie changes when filtering, so we use a fallback value that works
            try {
                $homepageResponse = $this->client->get($this->baseUrl . '/', [
                    'cookies' => $cookieJar,
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
                    ],
                    'allow_redirects' => true,
                ]);
                
                // Check what cookies we got
                $freshCookies = [];
                foreach ($cookieJar->toArray() as $cookie) {
                    $freshCookies[$cookie['Name']] = $cookie['Value'];
                }
                $this->info("Fresh cookies from server: " . json_encode(array_keys($freshCookies), JSON_PRETTY_PRINT));
                
                // Note: 'sid' cookie is set/updated during the filter POST process, not before
                // We'll use a fallback 'sid' that works for filtering
            } catch (\Exception $e) {
                $this->warn("Failed to get fresh cookies: " . $e->getMessage());
            }
            
            // Get current cookies after homepage visit
            $currentCookies = [];
            foreach ($cookieJar->toArray() as $cookie) {
                $currentCookies[$cookie['Name']] = $cookie['Value'];
            }
            
            // Ensure required cookies are present (fallback to defaults if not set by server)
            // sid_c appears to be a constant value '1'
            if (!isset($currentCookies['sid_c'])) {
                $sidCCookie = new SetCookie([
                    'Name' => 'sid_c',
                    'Value' => '1',
                    'Domain' => 'www.ss.com',
                ]);
                $cookieJar->setCookie($sidCCookie);
                $this->info("Set 'sid_c' cookie (constant value)");
            }
            
            // LG cookie (language) - default to 'lv' if not set
            if (!isset($currentCookies['LG'])) {
                $lgCookie = new SetCookie([
                    'Name' => 'LG',
                    'Value' => 'lv',
                    'Domain' => 'www.ss.com',
                ]);
                $cookieJar->setCookie($lgCookie);
                $this->info("Set 'LG' cookie (default: lv)");
            }
            
            // Rebuild cookie list after adding sid_c and LG
            $finalCookies = [];
            foreach ($cookieJar->toArray() as $cookie) {
                $finalCookies[$cookie['Name']] = $cookie['Value'];
            }
            
            if (!isset($finalCookies['PHPSESSID']) || !isset($finalCookies['sid'])) {
                $this->warn("⚠ Critical cookies (PHPSESSID or sid) missing. Using fallback values.");
                // Fallback: Use working values as last resort (these will expire, but better than nothing)
                if (!isset($finalCookies['PHPSESSID'])) {
                    $phpsessidCookie = new SetCookie([
                        'Name' => 'PHPSESSID',
                        'Value' => 'ae7d20eccfd19987f69248e06bf69cdf',
                        'Domain' => 'www.ss.com',
                    ]);
                    $cookieJar->setCookie($phpsessidCookie);
                    $this->warn("⚠ Using fallback PHPSESSID (will expire - needs long-term fix)");
                }
                if (!isset($finalCookies['sid'])) {
                    $sidCookie = new SetCookie([
                        'Name' => 'sid',
                        'Value' => '2476b762cc8251c397e7140105fae61419fde4958c4905a5e6bc3d337f25322a67b10a855dbd97a0c8147975465cd8c0',
                        'Domain' => 'www.ss.com',
                    ]);
                    $cookieJar->setCookie($sidCookie);
                    $this->warn("⚠ Using fallback sid (will expire - needs long-term fix)");
                }
            } else {
                $this->info("✓ Got fresh PHPSESSID and sid cookies from server");
            }

            // Get common headers
            $commonHeaders = $this->getCommonHeaders($fullUrl);

            // If we have form params, make a POST request directly (like testQuery does)
            if (!empty($formParams)) {
                // Create a copy of form params and add sid parameter with the full URL path
                $postFormParams = $formParams;
                $postFormParams['sid'] = $finalUrlPath;
                
                // Include all standard form fields (even empty) to match testQuery behavior
                // ss.com seems to expect all these fields to be present
                $standardFields = [
                    'topt[8][min]' => '',
                    'topt[8][max]' => '',
                    'topt[18][min]' => '',
                    'topt[18][max]' => '',
                    'topt[15][min]' => '',
                    'topt[15][max]' => '',
                    'opt[34]' => '',
                    'opt[35]' => '',
                    'opt[32]' => '',
                    'opt[17]' => '',
                ];
                
                // Merge standard fields with provided params (provided params override defaults)
                $allFormParams = array_merge($standardFields, $postFormParams);
                
                // Build the form data manually to match curl exactly (like testQuery does)
                // This ensures empty values are sent correctly
                // Order matters - use the same order as testQuery
                $orderedParams = [
                    'topt[8][min]',
                    'topt[8][max]',
                    'topt[18][min]',
                    'topt[18][max]',
                    'topt[15][min]',
                    'topt[15][max]',
                    'opt[34]',
                    'opt[35]',
                    'opt[32]',
                    'opt[17]',
                    'sid',
                ];
                
                $postDataParts = [];
                foreach ($orderedParams as $key) {
                    if (isset($allFormParams[$key])) {
                        $value = $allFormParams[$key];
                        // URL encode the key (brackets will be encoded as %5B and %5D)
                        $encodedKey = rawurlencode($key);
                        // For values, encode if not empty, otherwise leave as empty string
                        $encodedValue = ($value !== '' && $value !== null) ? rawurlencode($value) : '';
                        $postDataParts[] = $encodedKey . '=' . $encodedValue;
                    }
                }
                $postData = implode('&', $postDataParts);
                
                // Debug: Log cookies and POST data
                $cookies = [];
                foreach ($cookieJar->toArray() as $cookie) {
                    $cookies[$cookie['Name']] = $cookie['Value'];
                }
                $this->info("Cookies before POST: " . json_encode($cookies, JSON_PRETTY_PRINT));
                $this->info("POST Data: {$postData}");
                $this->info("Making POST request with form params: " . json_encode($postFormParams));
                
                // Make POST request without following redirects - we'll handle it manually
                $postHeaders = array_merge($commonHeaders, [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Origin' => $this->baseUrl,
                    'Priority' => 'u=0, i',
                ]);
                
                $postResponse = $this->client->post($fullUrl, [
                    'cookies' => $cookieJar,
                    'body' => $postData,
                    'headers' => $postHeaders,
                    'allow_redirects' => false,
                ]);
                
                $this->info("POST response status: " . $postResponse->getStatusCode());
                
                // POST should return 302 - follow it manually
                if ($postResponse->getStatusCode() >= 300 && $postResponse->getStatusCode() < 400) {
                    $location = $postResponse->getHeaderLine('Location');
                    $this->info("POST redirected to: {$location}");
                    
                    // If relative URL, make it absolute
                    if ($location && strpos($location, 'http') !== 0) {
                        if (strpos($location, '/') === 0) {
                            $redirectUrl = $this->baseUrl . $location;
                        } else {
                            $redirectUrl = $fullUrl . '/' . ltrim($location, '/');
                        }
                    } else {
                        $redirectUrl = $location ?: $fullUrl;
                    }
                    
                    $this->info("Making GET request to redirect location: {$redirectUrl}");
                    
                    // Make GET request to the redirect location
                    $finalResponse = $this->client->get($redirectUrl, [
                        'cookies' => $cookieJar,
                        'headers' => $commonHeaders,
                        'allow_redirects' => true,
                    ]);
                    
                    $this->info("GET response status: " . $finalResponse->getStatusCode());
                    
                    // Verify filter was applied by checking the HTML response
                    $html = $finalResponse->getBody()->getContents();
                    $crawler = new Crawler($html);
                    
                    // Check if key filter values are present in the form
                    $filterApplied = true;
                    foreach ($formParams as $key => $expectedValue) {
                        if (empty($expectedValue)) continue;
                        
                        // Try to find the form field by common patterns
                        $fieldId = null;
                        if (preg_match('/topt\[(\d+)\]\[(min|max)\]/', $key, $matches)) {
                            $fieldId = 'f_o_' . $matches[1] . '_' . $matches[2];
                        } elseif (preg_match('/opt\[(\d+)\]/', $key, $matches)) {
                            $fieldId = 'f_o_' . $matches[1];
                        }
                        
                        if ($fieldId) {
                            try {
                                $fieldElement = $crawler->filter('#' . $fieldId);
                                if ($fieldElement->count() > 0) {
                                    $tagName = $fieldElement->nodeName();
                                    if ($tagName === 'select') {
                                        $selectedOption = $fieldElement->filter('option[selected]');
                                        if ($selectedOption->count() > 0) {
                                            $selectedValue = $selectedOption->attr('value');
                                            if ($selectedValue != $expectedValue && $selectedValue != (string)(int)$expectedValue) {
                                                $this->warn("⚠ Filter {$key}: Expected '{$expectedValue}', got '{$selectedValue}'");
                                                $filterApplied = false;
                                            }
                                        }
                                    } elseif ($tagName === 'input') {
                                        $inputValue = $fieldElement->attr('value');
                                        if ($inputValue != $expectedValue) {
                                            $this->warn("⚠ Filter {$key}: Expected '{$expectedValue}', got '{$inputValue}'");
                                            $filterApplied = false;
                                        }
                                    }
                                }
                            } catch (\Exception $e) {
                                // Field not found, might be okay
                            }
                        }
                    }
                    
                    if ($filterApplied) {
                        $this->info("✓ Filters appear to be applied correctly");
                    } else {
                        $this->warn("⚠ Some filters may not have been applied correctly");
                    }
                    
                    // Reset body stream position for parsing
                    $finalResponse->getBody()->rewind();
                    return $finalResponse;
                } else {
                    // If no redirect, use POST response directly
                    return $postResponse;
                }
            } else {
                // If no form params, make a GET request to get the page
                $this->info("Making GET request to: {$fullUrl}");
                $getResponse = $this->client->get($fullUrl, [
                    'cookies' => $cookieJar,
                    'headers' => $commonHeaders,
                    'allow_redirects' => true,
                ]);
                $this->info("GET response status: " . $getResponse->getStatusCode());
                return $getResponse;
            }

        } catch (RequestException $e) {
            $this->error("Request failed: " . $e->getMessage());
            if ($e->hasResponse()) {
                $this->error("Response status: " . $e->getResponse()->getStatusCode());
                $this->error("Response body: " . $e->getResponse()->getBody()->getContents());
            }
            Log::error("SyncAds: Request failed for notification {$notification->id}", [
                'url' => $fullUrl ?? $urlPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        } catch (\Exception $e) {
            $this->error("Unexpected error: " . $e->getMessage());
            Log::error("SyncAds: Unexpected error for notification {$notification->id}", [
                'url' => $fullUrl ?? $urlPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Parse the response and check for new ads
     */
    private function parseAndCheckAds($response, AdNotification $notification, string $urlPath)
    {
        try {
            $html = $response->getBody()->getContents();
            $crawler = new Crawler($html);

            // Find the first ad link (links that start with /msg/)
            $firstAdLink = $crawler->filter('a[href^="/msg/"]')->first();

            if ($firstAdLink->count() === 0) {
                $this->warn("No ads found on the page for notification {$notification->id}");
                return;
            }

            $adUrl = $firstAdLink->attr('href');
            $this->info("Found first ad URL: {$adUrl}");

            // Extract the ad ID from the URL (the part after the last slash, before .html)
            // Example: /msg/lv/transport/cars/bmw/530/bdbgj.html -> bdbgj
            $adId = $this->extractAdIdFromUrl($adUrl);

            if (!$adId) {
                $this->warn("Could not extract ad ID from URL: {$adUrl}");
                return;
            }

            $this->info("Latest ad ID: {$adId}");

            // Check if this is different from the last known ad ID
            if ($notification->last_ad_id !== $adId) {
                // Build the full URL
                $fullUrl =  $adUrl;

                // Only send email if we've checked before (last_ad_id is not null)
                // This prevents sending emails on the first check
                // if ($notification->last_ad_id !== null) {
                    $this->info("New ad detected! Previous: {$notification->last_ad_id}, New: {$adId}");
                    $this->sendNotificationEmail($notification, $fullUrl);
                // } else {
                //     $this->info("Initial check completed. Setting last_ad_id to: {$adId}");
                // }

                // Update the last_ad_id
                $notification->update(['last_ad_id' => $adId]);
                $this->info("Updated last_ad_id for notification {$notification->id}");
            } else {
                $this->info("No new ads found. Last ad ID matches: {$adId}");
            }

        } catch (\Exception $e) {
            $this->error("Error parsing ads: " . $e->getMessage());
            Log::error("SyncAds: Error parsing ads for notification {$notification->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Extract ad ID from URL
     * Example: /msg/lv/transport/cars/bmw/530/bdbgj.html -> bdbgj
     */
    private function extractAdIdFromUrl(string $url): ?string
    {
        // Remove leading slash and get the last part before .html
        $url = trim($url, '/');
        $parts = explode('/', $url);
        $lastPart = end($parts);
        
        // Remove .html extension
        $adId = str_replace('.html', '', $lastPart);
        
        return !empty($adId) ? $adId : null;
    }

    /**
     * Send email notification to the user
     */
    private function sendNotificationEmail(AdNotification $notification, string $adUrl)
    {
        try {
            // Load user if not already loaded
            if (!$notification->relationLoaded('user')) {
                $notification->load('user');
            }
            
            $user = $notification->user;
            
            if (!$user) {
                $this->warn("User {$notification->user_id} not found. Skipping email.");
                Log::warning("SyncAds: User not found for notification {$notification->id}", [
                    'user_id' => $notification->user_id
                ]);
                return;
            }
            
            if (empty($user->email)) {
                $this->warn("User {$notification->user_id} has no email address. Skipping email.");
                Log::warning("SyncAds: User has no email for notification {$notification->id}", [
                    'user_id' => $notification->user_id,
                    'user_email' => $user->email
                ]);
                return;
            }

            $subject = "SS-notifier: {$notification->name}";
            $message = "Jauns sludinājums atbilst jūsu meklēšanas kritērijiem!\n\n";
            $message .= "Skatīt sludinājumu: {$adUrl}\n\n";
            $message .= "Meklēšanas nosaukums: {$notification->name}";

            $this->info("Attempting to send email to {$user->email} for notification {$notification->id}");

            Mail::raw($message, function ($mail) use ($user, $subject) {
                $mail->to($user->email)
                     ->subject($subject);
            });

            $this->info("✓ Email sent successfully to {$user->email}");
            Log::info("SyncAds: Email sent successfully", [
                'notification_id' => $notification->id,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'ad_url' => $adUrl
            ]);

        } catch (\Exception $e) {
            $this->error("✗ Error sending email: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
            Log::error("SyncAds: Error sending email for notification {$notification->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $notification->user_id,
                'ad_url' => $adUrl
            ]);
        }
    }
}