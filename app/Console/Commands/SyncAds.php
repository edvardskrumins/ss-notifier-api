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
    protected $signature = 'ss:sync-ads';
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
        $this->info('Starting ad sync for active notifications...');
        Log::channel('worker')->info('Starting ad sync for active notifications...');

        $notifications = AdNotification::where('active', true)
            ->with(['category', 'user', 'filters.filter', 'filters.filterValue'])
            ->get();

        if ($notifications->isEmpty()) {
            $this->info('No active notifications found.');
            return 0;
        }

        Log::channel('worker')->info("Found {$notifications->count()} active notification(s).");
        $this->info("Found {$notifications->count()} active notification(s).");

        foreach ($notifications as $notification) {
            try {
                $this->processNotification($notification);
            } catch (\Exception $e) {
                $this->error("Error processing notification ID {$notification->id}: " . $e->getMessage());
                Log::channel('worker')->error("SyncAds: Error processing notification {$notification->id}", [
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
        Log::channel('worker')->info("Processing notification: {$notification->name} (ID: {$notification->id})");
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
            
            // Handle hardcoded filters 
            if (!$filter) {
                $pathPart = $this->extractUrlPathPart($notificationFilter);
                if ($pathPart && !in_array($pathPart, $urlPathParts, true)) {
                    $urlPathParts[] = $pathPart;
                }
                continue;
            }

            $formParam = $filter->form_param;

            // If form_param is 'sid' or null, add to URL path
            if ($formParam === 'sid' || $formParam === null) {
                $pathPart = $this->extractUrlPathPart($notificationFilter);
                if ($pathPart && !in_array($pathPart, $urlPathParts, true)) {
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
                $formParams[$formParam . '[min]'] = $range['min'];
            }
            if ($range['max'] !== null) {
                $formParams[$formParam . '[max]'] = $range['max'];
            }
        }

        $urlPath = rtrim($category->url, '/');

        if (!empty($urlPathParts)) {
            $urlPath .= '/' . implode('/', $urlPathParts);
        }
        
        Log::channel('worker')->info("Built URL (for initial GET): {$urlPath}");
        $this->info("Built URL (for initial GET): {$urlPath}");
        $this->info("Form params: " . json_encode($formParams));
        Log::channel('worker')->info("Form params: " . json_encode($formParams));

        $pageResponse = $this->executeQuery($urlPath, $formParams, $notification, $urlPathParts);
        
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

    private function executeQuery(string $urlPath, array $formParams, AdNotification $notification, array $urlPathParts = [])
    {
        try {
            $relativeUrlPath = $urlPath;
            if (strpos($relativeUrlPath, $this->baseUrl) === 0) {
                $relativeUrlPath = substr($relativeUrlPath, strlen($this->baseUrl));
            }
            $relativeUrlPath = rtrim($relativeUrlPath, '/');
            
            $finalUrlPath = $relativeUrlPath;
            if (!empty($formParams)) {
                $finalUrlPath .= '/filter/';
            }
            
            $fullUrl = $this->baseUrl . $finalUrlPath;

            $cookieJar = new CookieJar();
            
            // Visit homepage once to get PHPSESSID
            // @TODO: 'sid' cookie changes when filtering, so we use a fallback value that works
            try {
                $homepageResponse = $this->client->get($this->baseUrl . '/', [
                    'cookies' => $cookieJar,
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
                    ],
                    'allow_redirects' => true,
                ]);
                
            } catch (\Exception $e) {
                $this->warn("Failed to get fresh cookies: " . $e->getMessage());
            }
            
            $currentCookies = [];
            foreach ($cookieJar->toArray() as $cookie) {
                $currentCookies[$cookie['Name']] = $cookie['Value'];
            }
            
            if (!isset($currentCookies['sid_c'])) {
                $sidCCookie = new SetCookie([
                    'Name' => 'sid_c',
                    'Value' => '1',
                    'Domain' => 'www.ss.com',
                ]);
                $cookieJar->setCookie($sidCCookie);
            }
            
            if (!isset($currentCookies['LG'])) {
                $lgCookie = new SetCookie([
                    'Name' => 'LG',
                    'Value' => 'lv',
                    'Domain' => 'www.ss.com',
                ]);
                $cookieJar->setCookie($lgCookie);
            }

            if (!isset($currentCookies['sid'])) {
                $sidCookie = new SetCookie([
                    'Name' => 'sid',
                    'Value' => '2476b762cc8251c397e7140105fae61419fde4958c4905a5e6bc3d337f25322a67b10a855dbd97a0c8147975465cd8c0',
                    'Domain' => 'www.ss.com',
                ]);
                $cookieJar->setCookie($sidCookie);
            } 

            $commonHeaders = $this->getCommonHeaders($fullUrl);

            if (!empty($formParams)) {
                $postFormParams = $formParams;
                $postFormParams['sid'] = $finalUrlPath;
                
                // Build the form data from provided params only
                $postDataParts = [];
                foreach ($postFormParams as $key => $value) {
                    $encodedKey = rawurlencode($key);
                    $encodedValue = ($value !== '' && $value !== null) ? rawurlencode($value) : '';
                    $postDataParts[] = $encodedKey . '=' . $encodedValue;
                }
                $postData = implode('&', $postDataParts);
                
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
                
                // Follow redirect - always goes back to the filter URL
                if ($postResponse->getStatusCode() >= 300 && $postResponse->getStatusCode() < 400) {
                    return $this->client->get($fullUrl, [
                        'cookies' => $cookieJar,
                        'headers' => $commonHeaders,
                        'allow_redirects' => true,
                    ]);
                }
                
                return $postResponse;
            } else {
                $getResponse = $this->client->get($fullUrl, [
                    'cookies' => $cookieJar,
                    'headers' => $commonHeaders,
                    'allow_redirects' => true,
                ]);
                return $getResponse;
            }

        } catch (RequestException $e) {
            $this->error("Request failed: " . $e->getMessage());
            if ($e->hasResponse()) {
                $this->error("Response status: " . $e->getResponse()->getStatusCode());
                $this->error("Response body: " . $e->getResponse()->getBody()->getContents());
            }
            Log::channel('worker')->error("SyncAds: Request failed for notification {$notification->id}", [
                'url' => $fullUrl ?? $urlPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        } catch (\Exception $e) {
            $this->error("Unexpected error: " . $e->getMessage());
            Log::channel('worker')->error("SyncAds: Unexpected error for notification {$notification->id}", [
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

            $fullUrl = $firstAdLink->attr('href');
            $this->info("Found first ad URL: {$fullUrl}");

            // Extract the ad ID from the URL (the part after the last slash, before .html)
            // Example: /msg/lv/transport/cars/bmw/530/bdbgj.html -> bdbgj
            $adId = $this->extractAdIdFromUrl($fullUrl);

            if (!$adId) {
                $this->warn("Could not extract ad ID from URL: {$fullUrl}");
                return;
            }

            $this->info("Latest ad ID: {$adId}");

            if ($notification->last_ad_id !== $adId) {
                $this->info("New ad detected! Previous: {$notification->last_ad_id}, New: {$adId}");
                $this->sendNotificationEmail($notification, $fullUrl);
  
                $notification->update(['last_ad_id' => $adId]);
                $this->info("Updated last_ad_id for notification {$notification->id}");
            } else {
                $this->info("No new ads found. Last ad ID matches: {$adId}");
            }

        } catch (\Exception $e) {
            $this->error("Error parsing ads: " . $e->getMessage());
            Log::channel('worker')->error("SyncAds: Error parsing ads for notification {$notification->id}", [
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
                Log::channel('worker')->warning("SyncAds: User not found for notification {$notification->id}", [
                    'user_id' => $notification->user_id
                ]);
                return;
            }
            
            if (empty($user->email)) {
                $this->warn("User {$notification->user_id} has no email address. Skipping email.");
                Log::channel('worker')->warning("SyncAds: User has no email for notification {$notification->id}", [
                    'user_id' => $notification->user_id,
                    'user_email' => $user->email
                ]);
                return;
            }

            // Make adUrl a full URL
            $fullAdUrl = $adUrl;
            if (strpos($adUrl, 'http') !== 0) {
                $fullAdUrl = $this->baseUrl . '/' . ltrim($adUrl, '/');
            }

            // Fetch the ad page to extract details
            $adImage = null;
            $adDescription = null;
            $adOptionsList = null;
            $adPrice = null;

            try {
                $cookieJar = new CookieJar();
                $adPageResponse = $this->client->get($fullAdUrl, [
                    'cookies' => $cookieJar,
                    'headers' => $this->getCommonHeaders($fullAdUrl),
                    'allow_redirects' => true,
                ]);

                $adHtml = $adPageResponse->getBody()->getContents();
                $adCrawler = new Crawler($adHtml);

                // Extract image - try #msg_img_img first, then fall back to first gallery thumbnail
                $imageElement = $adCrawler->filter('#msg_img_img');
                if ($imageElement->count() > 0) {
                    $adImage = $imageElement->attr('src');
                } else {
                    // Try to get first image from gallery thumbnails
                    $firstThumbnail = $adCrawler->filter('.pic_dv_thumbnail a')->first();
                    if ($firstThumbnail->count() > 0) {
                        $adImage = $firstThumbnail->attr('href');
                    }
                }

                // Extract description
                $descriptionElement = $adCrawler->filter('#msg_div_msg');
                if ($descriptionElement->count() > 0) {
                    // Get inner HTML, but remove the options_list table and msg_div_spec (we'll get options_list separately)
                    $descriptionHtml = $descriptionElement->html();
                    // Remove the options_list table from description
                    $descriptionHtml = preg_replace('/<table[^>]*class="options_list"[^>]*>.*?<\/table>/is', '', $descriptionHtml);
                    // Remove msg_div_spec div
                    $descriptionHtml = preg_replace('/<div[^>]*id="msg_div_spec"[^>]*>.*?<\/div>/is', '', $descriptionHtml);
                    // Remove the price table that comes after msg_div_spec
                    $descriptionHtml = preg_replace('/<table[^>]*style="border-top:1px[^>]*>.*?<\/table>/is', '', $descriptionHtml);
                    $adDescription = trim($descriptionHtml);
                }

                // Extract options list table
                $optionsListElement = $adCrawler->filter('.options_list');
                if ($optionsListElement->count() > 0) {
                    $adOptionsList = $optionsListElement->outerHtml();
                }

                // Extract price
                $priceElement = $adCrawler->filter('span.ads_price#tdo_8');
                if ($priceElement->count() > 0) {
                    $adPrice = trim($priceElement->text());
                } else {
                    // Fallback: try any element with class ads_price
                    $priceElement = $adCrawler->filter('.ads_price')->first();
                    if ($priceElement->count() > 0) {
                        $adPrice = trim($priceElement->text());
                    }
                }
            } catch (\Exception $e) {
                $this->warn("Failed to fetch ad details: " . $e->getMessage());
                // Continue with email even if we can't fetch details
            }

            $subject = "SS-notifier: {$notification->name}";
            
            // Build HTML email
            $htmlMessage = "<h2>Jauns sludinājums atbilst jūsu meklēšanas kritērijiem!</h2>";
            $htmlMessage .= "<p><strong>Meklēšanas nosaukums:</strong> {$notification->name}</p>";
            
            if ($adPrice) {
                $htmlMessage .= "<p><strong>Cena:</strong> <strong style=\"font-size: 1.2em; color: #3fbc58;\">{$adPrice}</strong></p>";
            }
            
            $htmlMessage .= "<p><a href=\"{$fullAdUrl}\">Skatīt sludinājumu</a></p>";

            if ($adImage) {
                $htmlMessage .= "<p><img src=\"{$adImage}\" alt=\"Ad image\" style=\"max-width: 100%; height: auto;\"></p>";
            }

            if ($adOptionsList) {
                $htmlMessage .= "<div>{$adOptionsList}</div>";
            }

            if ($adDescription) {
                $htmlMessage .= "<div>{$adDescription}</div>";
            }

            $this->info("Attempting to send email to {$user->email} for notification {$notification->id}");

            Mail::send([], [], function ($mail) use ($user, $subject, $htmlMessage) {
                $mail->to($user->email)
                     ->subject($subject)
                     ->html($htmlMessage);
            });

            $this->info("✓ Email sent successfully to {$user->email}");
            Log::channel('worker')->info("SyncAds: Email sent successfully", [
                'notification_id' => $notification->id,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'ad_url' => $fullAdUrl
            ]);

        } catch (\Exception $e) {
            $this->error("✗ Error sending email: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
            Log::channel('worker')->error("SyncAds: Error sending email for notification {$notification->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $notification->user_id,
                'ad_url' => $adUrl
            ]);
        }
    }
}