<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;
use App\Models\Category;
use App\Models\Filter;
use App\Models\FilterValue;
use App\Models\CategoryRelationship;
use Carbon\Carbon;

class CrawlPages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ss:crawl-pages';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crawl ss.com pages and store the structure in the database';


    private const IGNORED_FILTER_CLASSES = [
        'w95',
        'w140',
    ];
    private $baseUrl = 'https://www.ss.com';
    private $locale = 'lv';
    private $maxDepth = 20;
    private $client;
    private $categoryStructure = [];
    private $visitedUrls = [];
    private $urlPath = [];
    private $pathKeys = [];

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
        
        $this->client = new Client([
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
            ]
        ]);
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = microtime(true);
        $this->info("Starting to crawl ss.com from: {$this->baseUrl}");
        $this->info("Maximum depth: {$this->maxDepth}");
        
        $this->scrapeHomePage();

        $endTime = microtime(true);
        
        $start = Carbon::createFromTimestamp($startTime);
        $end = Carbon::createFromTimestamp($endTime);
        $duration = $start->diffForHumans($end, true);
        
        $this->info("Crawling completed! Total execution time: {$duration}");
    }

    // /**
    //  * Test price filtering using the sid cookie
    //  */
    // private function testPriceFilteringWithSid()
    // {
    //     $this->info("=== Testing Price Filtering with SID Cookie ===");
        
    //     try {
    //         // Step 1: Get initial page and extract sid cookie
    //         $session = new \GuzzleHttp\Cookie\CookieJar();
    //         $response = $this->client->get($this->baseUrl . '/lv/transport/cars/audi/', [
    //             'cookies' => $session
    //         ]);
            
    //         $this->info("Initial page status: " . $response->getStatusCode());
            
    //         // Step 2: Submit form with price filters to set the session
    //         $postData = [
    //             'topt[8][min]' => '10000',
    //             'topt[8][max]' => '20000',
    //             'topt[18][min]' => '2020',
    //             'topt[18][max]' => '2023',
    //         ];
            
    //         $response = $this->client->post($this->baseUrl . '/lv/transport/cars/audi/filter/', [
    //             'form_params' => $postData,
    //             'cookies' => $session,
    //             'allow_redirects' => true,
    //         ]);
            
    //         $this->info("Form submission status: " . $response->getStatusCode());
            
    //         // Step 3: Now access the page again with the same session
    //         $response = $this->client->get($this->baseUrl . '/lv/transport/cars/audi/filter', [
    //             'cookies' => $session
    //         ]);
            
    //         $this->info("Return visit status: " . $response->getStatusCode());
            
    //         $crawler = new Crawler($response->getBody()->getContents());
            
    //         // Check if price values are now in the form fields
    //         $minPriceField = $crawler->filter('input[name="topt[8][min]"]')->attr('value');
    //         $maxPriceField = $crawler->filter('input[name="topt[8][max]"]')->attr('value');
            
    //         $this->info("Min price field value: '{$minPriceField}'");
    //         $this->info("Max price field value: '{$maxPriceField}'");
            
    //         if ($minPriceField === '10000' || $maxPriceField === '20000') {
    //             $this->info("✓ SUCCESS! Price values found in form fields!");
    //             $this->info("This means price filtering works with session cookies!");
    //             $this->extractCarListings($crawler);
    //         } else {
    //             $this->info("✗ Price values not found in form fields");
    //         }
            
    //         // Step 4: Test if we can create filtered URLs using the session
    //         $this->testFilteredUrlsWithSession($session);
            
    //     } catch (RequestException $e) {
    //         $this->error("SID cookie test failed: " . $e->getMessage());
    //     }
    // }

    

    /**
     * Extract page title from crawler
     */
    private function extractPageTitle(Crawler $crawler): string
    {
        $breadcrumbElement = $crawler->filter('h2.headtitle');
        if ($breadcrumbElement->count() > 0) {
            $breadcrumbText = $breadcrumbElement->text();
            
            // Split by " / " and get the last part
            $parts = explode(' / ', $breadcrumbText);
            if (count($parts) > 1) {
                $lastPart = trim(end($parts));
                // Remove any trailing text after the main title (like "img" tags)
                $lastPart = preg_replace('/\s+<.*$/', '', $lastPart);
                if (!empty($lastPart)) {
                    return $lastPart;
                }
            }
        }
        
        // Fallback to HTML title tag
        $titleElement = $crawler->filter('title');
        if ($titleElement->count() > 0) {
            $title = $titleElement->text();
            if (!empty($title)) {
                return $title;
            }
        }
        
        return 'Unknown Title';
    }

    private function scrapeHomePage()
    {
        $response = $this->makeRequestWithRetry($this->baseUrl . '/' . $this->locale);

        $crawler = new Crawler($response->getBody()->getContents());

        $categories = $crawler->filter('.main_head2 a')->each(function (Crawler $node) {
            return [
                'href' => $node->attr('href'),
                'title' => $node->attr('title'),
                'text' => $node->text()
            ];
        });


        // For testing speed purpose
        // $categories = array_slice($categories, 9, 1);
        // $categories = array_slice($categories, 1, 1);
        
        $this->info("Found " . count($categories) . " categories");

        foreach ($categories as $category) {
            $this->info("Scraping category: " . $category['text']);
            
            $categoryModel = Category::updateOrCreate(
                ['url' => $this->baseUrl . $category['href']], 
                [
                    'title' => $category['title'],
                    'type' => Category::TYPE_CATEGORY,
                ]
            );
            
            $this->crawlCategoryPage($category['href'], $categoryModel->id, 1);
        }
    }


    /**
     * Crawl a category page and its subcategories
     * Returns the crawler if it's an ads page, null if it has subcategories
     */
    private function crawlCategoryPage(string $url, int $parentId, int $depth): ?Crawler
    {
        // Create a unique identifier for this URL + parent_id combination
        $visitKey = $url;
        
        // Add current URL and visit key to path for tracking
        $this->urlPath[] = $url;
        $this->pathKeys[] = $visitKey;
        
        // Create path display string showing previous URLs with their visit keys
        $pathString = '';
        if (count($this->urlPath) > 1) {
            $pathParts = [];
            for ($i = 0; $i < count($this->urlPath); $i++) {
                $pathParts[] = $this->urlPath[$i] . '[' . $this->pathKeys[$i] . ']';
            }
            $pathString = ' (path: ' . implode(' → ', $pathParts) . ')';
        }
        
        $this->info("Crawling category page: {$url} (depth: {$depth}){$pathString}");
        
        // // Check for loops by looking for repeated URLs in the current path
        $urlCount = array_count_values($this->urlPath);
        if ($urlCount[$url] > 1) {
            $this->info("  -> LOOP DETECTED: URL '{$url}' appears " . $urlCount[$url] . " times in current path, stopping");
            // Remove current URL from path before returning
            array_pop($this->urlPath);
            array_pop($this->pathKeys);
            return null;
        }
        
        // // Check if we've already visited this URL + parent_id combination
        // if (isset($this->visitedUrls[$visitKey])) {
        //     $this->info("  -> Already visited: {$url} with parent_id {$parentId}, skipping to prevent loop");
        //     // Remove current URL from path before returning
        //     array_pop($this->urlPath);
        //     array_pop($this->pathKeys);
        //     return null;
        // }

        // Mark this URL + parent_id combination as visited
        $this->visitedUrls[$visitKey] = true;

        if ($depth > $this->maxDepth) {
            $this->info("  -> Max depth reached, stopping");
            // Remove current URL from path before returning
            array_pop($this->urlPath);
            array_pop($this->pathKeys);
            return null;
        }


        try {
            $response = $this->makeRequestWithRetry($this->baseUrl . $url);
            $crawler = new Crawler($response->getBody()->getContents());

            // Check if this is an ads page
            if ($crawler->filter('#head_line')->count() > 0) {
                $this->info("  -> Ads page detected");
                
                // Don't create the category here - it will be created by the parent caller
                // Return the crawler for filter extraction
                // Remove current URL from path before returning
                array_pop($this->urlPath);
                array_pop($this->pathKeys);
                return $crawler;
            }

            // Extract subcategories
            $subcategories = $crawler->filter('h4.category a')->each(function (Crawler $node) {
                return [
                    'href' => $node->attr('href'),
                    'title' => $node->attr('title'),
                    'text' => $node->text()
                ];
            });

            // Filter out subcategories without href
            $subcategories = array_filter($subcategories, function ($subcategory) {
                return !empty($subcategory['href']);
            });

            $this->info("  -> Found " . count($subcategories) . " subcategories");
            
            foreach ($subcategories as $subcategory) {
                $this->info("    - {$subcategory['text']} ({$subcategory['href']})");
                
                $fullUrl = $this->baseUrl . $subcategory['href'];
                
                // Check if this category already exists
                $existingCategory = Category::where('url', $fullUrl)->first();
                
                if ($existingCategory) {
                    // Category exists - check if we need to create a relationship
                    $this->info("      -> Category already exists (ID: {$existingCategory->id})");
                    
                    // Check if there's already a relationship between parent and this category
                    $existingRelationship = CategoryRelationship::where('parent_id', $parentId)
                        ->where('child_id', $existingCategory->id)
                        ->first();
                    
                    if (!$existingRelationship) {
                        // Create a cross-reference relationship
                        CategoryRelationship::create([
                            'parent_id' => $parentId,
                            'child_id' => $existingCategory->id,
                        ]);
                        $this->info("      -> Created cross-reference relationship");
                    } else {
                        $this->info("      -> Relationship already exists");
                        continue;
                    }
                    
                    $subcategoryModel = $existingCategory;
                } else {
                    // Category doesn't exist - create it
                    $subcategoryModel = Category::create([
                        'url' => $fullUrl,
                        'title' => $subcategory['text'],
                        'type' => Category::TYPE_SUBCATEGORY,
                    ]);
                    
                    // Create the primary hierarchy relationship
                    CategoryRelationship::create([
                        'parent_id' => $parentId,
                        'child_id' => $subcategoryModel->id,
                    ]);
                    
                    $this->info("      -> Created new category (ID: {$subcategoryModel->id}) with hierarchy relationship");
                }
                
                // Now recursively crawl to see what this subcategory contains
                $adsCrawler = $this->crawlCategoryPage($subcategory['href'], $subcategoryModel->id, $depth + 1);
                
                // Check if this subcategory is actually an ads page
                if ($adsCrawler) {
                    // This is an ads page - update the type and extract filters
                    $subcategoryModel->update(['type' => Category::TYPE_ADS]);
                    
                    // Use the returned crawler to extract filters
                    try {
                        $this->extractFilters($adsCrawler, $subcategoryModel->id);
                    } catch (\Exception $e) {
                        $this->error("Failed to extract filters for ads page {$subcategory['href']}: " . $e->getMessage());
                    }
                }
                
                usleep(2 * 100000); 
            }

            // Return null if we found subcategories to process (not an ads page)
            // Remove current URL from path before returning
            array_pop($this->urlPath);
            array_pop($this->pathKeys);
            return null;

        } catch (RequestException $e) {
            $this->error("Failed to crawl category page {$url}: " . $e->getMessage());
            Log::error("Crawl error for {$url}: " . $e->getMessage());
            // Remove current URL from path before returning
            array_pop($this->urlPath);
            array_pop($this->pathKeys);
            return null;
        }
    }

    private function extractAdsFilters(Crawler $crawler, string $url, int $categoryId): array
    {
        $filters = [
            'url' => $url,
            'title' => $this->extractPageTitle($crawler),
            'filters' => []
        ];

        $allFilters = $this->extractFilters($crawler, $categoryId);
        dd($allFilters);
        $filters['filters'] = $this->groupFiltersByTypeAndLabel($allFilters);

        return $filters;
    }

    // private function groupFiltersByTypeAndLabel(array $allFilters): array
    // {
    //     $filters = [];

    //     foreach ($allFilters as $filter) {
    //         $filterType = $filter['type'];
    //         if (!isset($filters['filters'][$filterType])) {
    //             $filters['filters'][$filterType] = [];
    //         }
    //         $filters['filters'][$filterType][$filter['label']] = $filter;
    //     }

    //     return $filters;
    // }


    private function extractFilters(Crawler $crawler, int $categoryId): array
    {
        $firstLineFilters = $this->extractFirstLineFilters($crawler, $categoryId);
        $secondLineFilters = $this->extractSecondLineFilters($crawler, $categoryId);

        return array_merge($firstLineFilters, $secondLineFilters);
    }

    private function classifyAndExtractFilter(Crawler $cell, string $label, int $categoryId): ?array
    {
        // Check for custom_range: text inputs with min/max
                $minInput = $cell->filter('input[type="text"][name$="[min]"]');
                $maxInput = $cell->filter('input[type="text"][name$="[max]"]');
                
                if ($minInput->count() > 0 || $maxInput->count() > 0) {
                    $name = $this->extractFieldName($minInput->count() > 0 ? $minInput : $maxInput);

            Filter::updateOrCreate([
                'label' => $label,
                'category_id' => $categoryId,
            ], [
                'label' => $label,
                'category_id' => $categoryId,
                'type' => Filter::TYPE_CUSTOM_RANGE,
                'form_param' => $name,
            ]);


            return [
                'type' => 'custom_range',
                'name' => $name,
                'label' => $label,
                'min_field' => $minInput->count() > 0 ? $minInput->attr('name') : null,
                'max_field' => $maxInput->count() > 0 ? $maxInput->attr('name') : null,
            ];
        }

        // Check for select_range: select dropdowns with min/max
                $minSelect = $cell->filter('select[name$="[min]"]');
                $maxSelect = $cell->filter('select[name$="[max]"]');
                
                if ($minSelect->count() > 0 || $maxSelect->count() > 0) {
                    $name = $this->extractFieldName($minSelect->count() > 0 ? $minSelect : $maxSelect);
                    $minOptions = $minSelect->count() > 0 ? $this->extractSelectOptions($minSelect) : [];
                    $maxOptions = $maxSelect->count() > 0 ? $this->extractSelectOptions($maxSelect) : [];
                    
            $selectRangeFilter = Filter::updateOrCreate([
                'label' => $label,
                'category_id' => $categoryId,
            ], [
                'label' => $label,
                'category_id' => $categoryId,
                'type' => Filter::TYPE_SELECT_RANGE,
                'form_param' => $name,
            ]);

            foreach($minOptions as $minOption) {
                if($minOption['value'] == '' || $minOption['value'] == null) {
                    continue;
                }

                FilterValue::updateOrCreate([
                    'filter_id' => $selectRangeFilter->id,
                    'value' => $minOption['value'],
                ], [
                    'filter_id' => $selectRangeFilter->id,
                    'value' => $minOption['value'],
                    'label' => $minOption['label'],
                ]);
            }

            foreach($maxOptions as $maxOption) {
                if($maxOption['value'] == '' || $maxOption['value'] == null) {
                    continue;
                }

                FilterValue::updateOrCreate([
                    'filter_id' => $selectRangeFilter->id,
                    'value' => $maxOption['value'],
                ], [
                    'filter_id' => $selectRangeFilter->id,
                    'value' => $maxOption['value'],
                    'label' => $maxOption['label'],
                ]);
            }

            return [
                        'type' => 'select_range',
                        'name' => $name,
                        'label' => $label,
                        'min_options' => $minOptions,
                        'max_options' => $maxOptions
                    ];
                }

        // Check for custom_text: single text input without min/max
        $textInput = $cell->filter('input[type="text"]:not([name$="[min]"]):not([name$="[max]"])');
        if ($textInput->count() > 0) {
            $name = $textInput->attr('name');

            Filter::updateOrCreate([
                'label' => $label,
                'category_id' => $categoryId,
            ], [
                'label' => $label,
                'category_id' => $categoryId,
                'type' => Filter::TYPE_CUSTOM_TEXT,
                'form_param' => $name,
                'max_length' => $textInput->attr('maxlength'),
            ]);

            return [
                'type' => 'custom_text',
                'name' => $name,
                'label' => $label,
                'max_length' => $textInput->attr('maxlength'),
            ];
        }

        // Check for select: single select dropdown (not min/max)
        $select = $cell->filter('select:not([name$="[min]"]):not([name$="[max]"])');
        if ($select->count() > 0) {
            if ($this->hasIgnoredClass($select)) {
                return null;
            }
            
            $name = $select->attr('name');
            $options = $this->extractSelectOptions($select);
            
            if (!empty($options)) {
                $onChange = $select->attr('onchange');
                $type = ($onChange && strpos($onChange, 'this.form.submit()') !== false) 
                    ? Filter::TYPE_FORM_SELECT
                    : Filter::TYPE_SELECT;
                

                $selectFilter = Filter::updateOrCreate([
                    'label' => $label,
                    'category_id' => $categoryId,
                ], [
                    'label' => $label,
                    'category_id' => $categoryId,
                    'type' => Filter::TYPE_SELECT,
                    'form_param' => $name,
                ]);

                foreach($options as $option) {
                    if($option['value'] == '' || $option['value'] == null) {
                        continue;
                    }

                    FilterValue::updateOrCreate([
                        'filter_id' => $selectFilter->id,
                        'value' => $option['value'],
                    ], [
                        'filter_id' => $selectFilter->id,
                        'value' => $option['value'],
                        'label' => $option['label'],
                    ]);
                }
                return [
                    'type' => $type,
                    'name' => $name,
                    'label' => $label,
                    'options' => $options
                ];

            }

            // if (!empty($options)) {
            //     $onChange = $select->attr('onchange');
            //     $type = ($onChange && strpos($onChange, 'this.form.submit()') !== false) 
            //         ? 'form_select' 
            //         : 'select';
                
                // }
        }

        return null;
    }

    private function extractFirstLineFilters(Crawler $crawler, int $categoryId): array
    {
        $filters = [];

        $filterRows = $crawler->filter('#filter_tbl tr');
        
        $filterRows->each(function (Crawler $row) use (&$filters, $categoryId) {
            $filterCells = $row->filter('td.filter_name');
            
            $filterCells->each(function (Crawler $cell) use (&$filters, $categoryId) {
                $label = $this->extractFilterLabel($cell);
                if (!$label) return;
                
                // Classify and extract the filter based on its structure
                $filter = $this->classifyAndExtractFilter($cell, $label, $categoryId);

                if ($filter) {
                    $filters[] = $filter;
                }
            });
        });

         return $filters;
    }
    
    private function extractSecondLineFilters(Crawler $crawler, int $categoryId): array
    {
        $filters = [];

        $filterRows = $crawler->filter('.filter_second_line_dv');

        $filterRows->each(function (Crawler $row) use (&$filters, $categoryId) {
            $filterCells = $row->filter('span.filter_opt_dv');

            $filterCells->each(function (Crawler $cell) use (&$filters, $categoryId) {
                $label = $this->extractFilterLabel($cell);
                if (!$label) return;
                
                $filter = $this->classifyAndExtractFilter($cell, $label, $categoryId);

                if ($filter) {
                    $filters[] = $filter;
                }
            });
        });


         return $filters;
    }


    private function getHardcodedRegionOptions(): array
    {
        return [
            [
                'value' => '0',
                'text' => 'Visi sludinājumi',
            ],
            [
                'value' => 'riga_f',
                'text' => 'Rīga',
            ],
            [
                'value' => 'yurmala_f',
                'text' => 'Jūrmala',
            ],
            [
                'value' => 'riga_region_f',
                'text' => 'Rīgas rajons',
            ],
            [
                'value' => 'aizkraukle_f',
                'text' => 'Aizkraukle un raj.',
            ],
            [
                'value' => 'aluksne_f',
                'text' => 'Alūksne un raj.',
            ],
            [
                'value' => 'balvi_f',
                'text' => 'Balvi un raj.',
            ],
            [
                'value' => 'bauska_f',
                'text' => 'Bauska un raj.',
            ],
            [
                'value' => 'cesis_f',
                'text' => 'Cēsis un raj.',
            ],
            [
                'value' => 'daugavpils_f',
                'text' => 'Daugavpils un raj.',
            ],
            [
                'value' => 'dobele_f',
                'text' => 'Dobele un raj.',
            ],
            [
                'value' => 'gulbene_f',
                'text' => 'Gulbene un raj.',
            ],
            [
                'value' => 'jekabpils_f',
                'text' => 'Jēkabpils un raj.',
            ],
            [
                'value' => 'jelgava_f',
                'text' => 'Jelgava un raj.',
            ],
            [
                'value' => 'kraslava_f',
                'text' => 'Krāslava un raj.',
            ],
            [
                'value' => 'kuldiga_f',
                'text' => 'Kuldīga un raj.',
            ],
            [
                'value' => 'liepaja_f',
                'text' => 'Liepāja un raj.',
            ],
            [
                'value' => 'limbadzi_f',
                'text' => 'Limbaži un raj.',
            ],
            [
                'value' => 'ludza_f',
                'text' => 'Ludza un raj.',
            ],
            [
                'value' => 'madona_f',
                'text' => 'Madona un raj.',
            ],
            [
                'value' => 'ogre_f',
                'text' => 'Ogre un raj.',
            ],
            [
                'value' => 'preili_f',
                'text' => 'Preiļi un raj.',
            ],
            [
                'value' => 'rezekne_f',
                'text' => 'Rēzekne un raj.',
            ],
            [
                'value' => 'saldus_f',
                'text' => 'Saldus un raj.',
            ],
            [
                'value' => 'talsi_f',
                'text' => 'Talsi un raj.',
            ],
            [
                'value' => 'tukums_f',
                'text' => 'Tukums un raj.',
            ],
            [
                'value' => 'valka_f',
                'text' => 'Valka un raj.',
            ],
            [
                'value' => 'valmiera_f',
                'text' => 'Valmiera un raj.',
            ],
            [
                'value' => 'ventspils_f',
                'text' => 'Ventspils un raj.',
            ],
            [
                'value' => 'estonia_f',
                'text' => 'Igaunija',
            ],
            [
                'value' => 'lithuania_f',
                'text' => 'Lietuva',
            ]
        ];
    }

    private function hasIgnoredClass(Crawler $select): bool
    {
        $class = $select->attr('class');
        if (!$class) {
            return false;
        }
        
        foreach (self::IGNORED_FILTER_CLASSES as $ignoredClass) {
            if (strpos($class, $ignoredClass) !== false) {
                return true;
            }
        }
        
        return false;
    }

    private function extractFilterLabel(Crawler $cell): ?string
    {
        $labelText = $cell->text();
        if (empty($labelText)) return null;
        
        $parts = explode(':', $labelText);
        return trim($parts[0]) ?: null;
    }


    private function extractFieldName(Crawler $element): string
    {
        $name = $element->attr('name');
        if (!$name) return '';
        
        return preg_replace('/\[(min|max)\]$/', '', $name);
    }


    private function extractSelectOptions(Crawler $select): array
    {
        return $select->filter('option')->each(function (Crawler $node) {
            return [
                'label' => trim($node->text()),
                'value' => $node->attr('value'),
            ];
        });
    }

    /**
     * Make HTTP request with retry mechanism and exponential backoff
     */
    private function makeRequestWithRetry(string $url, int $maxRetries = 5): \Psr\Http\Message\ResponseInterface
    {
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->info("Making request to: {$url} (attempt {$attempt}/{$maxRetries})");
                
                $response = $this->client->get($url);
                
                // If successful, return immediately
                if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                    $this->info("Request successful: {$url}");
                    return $response;
                }
                
                throw new \Exception("HTTP {$response->getStatusCode()} response");
                
            } catch (\GuzzleHttp\Exception\ConnectException $e) {
                $lastException = $e;
                $this->warn("Connection error for {$url} (attempt {$attempt}): " . $e->getMessage());
                
                if ($attempt < $maxRetries) {
                    $delay = $this->calculateDelay($attempt);
                    $this->info("Retrying in {$delay} seconds...");
                    sleep($delay);
                }
                
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                $lastException = $e;
                $this->warn("Request error for {$url} (attempt {$attempt}): " . $e->getMessage());
                
                if ($attempt < $maxRetries) {
                    $delay = $this->calculateDelay($attempt);
                    $this->info("Retrying in {$delay} seconds...");
                    sleep($delay);
                }
                
            } catch (\Exception $e) {
                $lastException = $e;
                $this->warn("General error for {$url} (attempt {$attempt}): " . $e->getMessage());
                
                if ($attempt < $maxRetries) {
                    $delay = $this->calculateDelay($attempt);
                    $this->info("Retrying in {$delay} seconds...");
                    sleep($delay);
                }
            }
        }
        
        // If all retries failed, log the error and throw
        $this->error("All retry attempts failed for {$url}. Last error: " . $lastException->getMessage());
        Log::error("HTTP request failed after {$maxRetries} attempts", [
            'url' => $url,
            'error' => $lastException->getMessage(),
            'attempts' => $maxRetries
        ]);
        
        throw $lastException;
    }
    
    /**
     * Calculate delay for exponential backoff
     */
    private function calculateDelay(int $attempt): int
    {
        $delays = [30, 60, 180, 360, 720, 1440];
        return $delays[min($attempt - 1, count($delays) - 1)];
    }
  
}
