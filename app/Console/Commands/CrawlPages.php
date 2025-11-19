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
use App\Services\SparePartsCategoryService;
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
    
    private const GENERIC_SPARE_PARTS_CATEGORIES = [
        'trunks-wheels',
        'accumulators',
        'audio-video',
        'chargers',
        'rear-view-cameras',
        'signallings',
        'candles',
        'video-recorders',
        'crankcase-protection',
        'radar-detectors',
        'all'
    ];

    /**
     * Temporary ignore list for car brand URLs that should not be traversed further
     */
    private const IGNORED_CAR_BRAND_URLS = [
        'https://www.ss.com/lv/transport/spare-parts/alfa-romeo/',
        'https://www.ss.com/lv/transport/spare-parts/alpina/',
        'https://www.ss.com/lv/transport/spare-parts/audi/',
        'https://www.ss.com/lv/transport/spare-parts/austin/',
        'https://www.ss.com/lv/transport/spare-parts/bentley/',
        'https://www.ss.com/lv/transport/spare-parts/bmw/',
        'https://www.ss.com/lv/transport/spare-parts/bugatti/',
        'https://www.ss.com/lv/transport/spare-parts/buick/',
        'https://www.ss.com/lv/transport/spare-parts/cadillac/',
        'https://www.ss.com/lv/transport/spare-parts/chevrolet/',
        'https://www.ss.com/lv/transport/spare-parts/chrysler/',
        'https://www.ss.com/lv/transport/spare-parts/citroen/',
        'https://www.ss.com/lv/transport/spare-parts/cupra/',
        'https://www.ss.com/lv/transport/spare-parts/dacia/',
        'https://www.ss.com/lv/transport/spare-parts/daewoo/',
        'https://www.ss.com/lv/transport/spare-parts/daihatsu/',
        'https://www.ss.com/lv/transport/spare-parts/daimler/',
        'https://www.ss.com/lv/transport/spare-parts/dodge/',
    ];

    private $baseUrl = 'https://www.ss.com';
    private $locale = 'lv';
    private $maxDepth = 20;
    private $client;
    private $categoryStructure = [];
    private $visitedUrls = [];
    private $urlPath = [];
    private $pathKeys = [];
    private $sparePartsService;

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
        
        $this->sparePartsService = new SparePartsCategoryService();
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


    /**
     * Extract page title from crawler
     */
    private function extractPageTitle(Crawler $crawler): string
    {
        $breadcrumbElement = $crawler->filter('h2.headtitle');
        if ($breadcrumbElement->count() > 0) {
            $breadcrumbText = $breadcrumbElement->text();
            
            // Split by / and get the last part
            $parts = explode(' / ', $breadcrumbText);
            if (count($parts) > 1) {
                $lastPart = trim(end($parts));
                // Remove any trailing text after the main title like img tags
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
        $visitKey = $url;
        $this->urlPath[] = $url;
        $this->pathKeys[] = $visitKey;
        
        $pathString = '';
        if (count($this->urlPath) > 1) {
            $pathParts = [];
            for ($i = 0; $i < count($this->urlPath); $i++) {
                $pathParts[] = $this->urlPath[$i] . '[' . $this->pathKeys[$i] . ']';
            }
            $pathString = ' (path: ' . implode(' → ', $pathParts) . ')';
        }
        
        $this->info("Crawling category page: {$url} (depth: {$depth}){$pathString}");
        
        if ($this->isInTransportSparePartSection($url)) {
            $sparePartsDepth = $this->getSparePartsDepth($url);
            if ($sparePartsDepth == 2) {
                // Check if this is a generic spare parts category that should be processed normally
                $isGenericCategory = $this->isGenericSparePartsCategory($url);
                
                if ($isGenericCategory) {
                    $this->info("  -> Generic spare parts category detected, continuing normal crawling");
                } else {
                    $this->info("  -> Transport spare part section at depth 2, generating spare parts categories instead of crawling");
                    
                    // Generate spare parts categories dynamically for any car brand/model
                    $this->info("  -> Generating spare parts categories for: {$url}");
                    $this->generateSparePartsCategoriesForUrl($this->baseUrl . $url, $parentId);
                    
                    // Remove current URL from path before returning
                    array_pop($this->urlPath);
                    array_pop($this->pathKeys);
                    return null;
                }
            }
        }
        
        $urlCount = array_count_values($this->urlPath);
        if ($urlCount[$url] > 1) {
            $this->info("  -> LOOP DETECTED: URL '{$url}' appears " . $urlCount[$url] . " times in current path, stopping");
            array_pop($this->urlPath);
            array_pop($this->pathKeys);
            return null;
        }
        
        if ($this->shouldIgnoreUrl($url)) {
            $this->info("  -> IGNORED: URL '{$url}' is in the temporary ignore list, skipping");
            array_pop($this->urlPath);
            array_pop($this->pathKeys);
            return null;
        }
        
        if (isset($this->visitedUrls[$visitKey])) {
            $this->info("  -> Already visited: {$url} with parent_id {$parentId}, skipping to prevent loop");
            array_pop($this->urlPath);
            array_pop($this->pathKeys);
            return null;
        }

        $this->visitedUrls[$visitKey] = true;

        if ($depth > $this->maxDepth) {
            $this->info("  -> Max depth reached, stopping");
            array_pop($this->urlPath);
            array_pop($this->pathKeys);
            return null;
        }

        try {
            $response = $this->makeRequestWithRetry($this->baseUrl . $url);
            $crawler = new Crawler($response->getBody()->getContents());

            if ($crawler->filter('#head_line')->count() > 0) {
                $this->info("  -> Ads page detected");
                
                // Don't create the category here - it will be created by the parent caller
                array_pop($this->urlPath);
                array_pop($this->pathKeys);
                return $crawler;
            }

            $subcategories = $crawler->filter('h4.category a')->each(function (Crawler $node) {
                return [
                    'href' => $node->attr('href'),
                    'title' => $node->attr('title'),
                    'text' => $node->text()
                ];
            });

            $subcategories = array_filter($subcategories, function ($subcategory) {
                return !empty($subcategory['href']);
            });

            $this->info("  -> Found " . count($subcategories) . " subcategories");
            
            foreach ($subcategories as $subcategory) {
                $this->info("    - {$subcategory['text']} ({$subcategory['href']})");
                
                $fullUrl = $this->baseUrl . $subcategory['href'];
                
                $existingCategory = Category::where('url', $fullUrl)->first();
                
                if ($existingCategory) {
                    $this->info("      -> Category already exists (ID: {$existingCategory->id})");
                    
                    $existingRelationship = CategoryRelationship::where('parent_id', $parentId)
                        ->where('child_id', $existingCategory->id)
                        ->first();
                    
                    if (!$existingRelationship) {
                        CategoryRelationship::updateOrCreate([
                            'parent_id' => $parentId,
                            'child_id' => $existingCategory->id,
                        ]);
                        $this->info("      -> Created/updated relationship");
                    } else {
                        $this->info("      -> Relationship already exists");
                    }
                    
                    $subcategoryModel = $existingCategory;
                    
                    if ($existingCategory->type === Category::TYPE_ADS) {
                        $this->info("      -> Category is ads page, skipping recursive crawl");
                        array_pop($this->urlPath);
                        array_pop($this->pathKeys);
                        continue;
                    }
                } else {
                    $subcategoryModel = Category::updateOrCreate(
                        ['url' => $fullUrl],
                        [
                            'title' => $subcategory['text'],
                            'type' => Category::TYPE_SUBCATEGORY,
                        ]
                    );
                    
                    CategoryRelationship::updateOrCreate([
                        'parent_id' => $parentId,
                        'child_id' => $subcategoryModel->id,
                    ]);
                    
                    $this->info("      -> Created/updated category (ID: {$subcategoryModel->id})");
                }
                
                $adsCrawler = $this->crawlCategoryPage($subcategory['href'], $subcategoryModel->id, $depth + 1);
                
                if ($adsCrawler) {
                    $subcategoryModel->update(['type' => Category::TYPE_ADS]);
                    
                    try {
                        $this->extractFilters($adsCrawler, $subcategoryModel->id);
                    } catch (\Exception $e) {
                        $this->error("Failed to extract filters for ads page {$subcategory['href']}: " . $e->getMessage());
                    }
                }
                
                // usleep(2 * 100000); 
            }

            // Return null if found subcategories to process 
            array_pop($this->urlPath);
            array_pop($this->pathKeys);
            return null;

        } catch (RequestException $e) {
            $this->error("Failed to crawl category page {$url}: " . $e->getMessage());
            Log::channel('worker')->error("Crawl error for {$url}: " . $e->getMessage());
            array_pop($this->urlPath);
            array_pop($this->pathKeys);
            return null;
        }
    }


    private function extractFilters(Crawler $crawler, int $categoryId): array
    {
        $firstLineFilters = $this->extractFirstLineFilters($crawler, $categoryId);
        $secondLineFilters = $this->extractSecondLineFilters($crawler, $categoryId);

        return array_merge($firstLineFilters, $secondLineFilters);
    }

    private function classifyAndExtractFilter(Crawler $cell, string $label, int $categoryId): ?array
    {
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


    private function makeRequestWithRetry(string $url, int $maxRetries = 5): \Psr\Http\Message\ResponseInterface
    {
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->info("Making request to: {$url} (attempt {$attempt}/{$maxRetries})");
                
                $response = $this->client->get($url);
                
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
        
        $this->error("All retry attempts failed for {$url}. Last error: " . $lastException->getMessage());
        Log::channel('worker')->error("HTTP request failed after {$maxRetries} attempts", [
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


    private function isInTransportSparePartSection(string $url): bool
    {
        return preg_match('/\/transport\/spare-parts\//', $url) === 1;
    }


    private function isGenericSparePartsCategory(string $url): bool
    {
        foreach (self::GENERIC_SPARE_PARTS_CATEGORIES as $category) {
            if (strpos($url, '/' . $category . '/') !== false) {
                return true;
            }
        }
        return false;
    }

    private function shouldIgnoreUrl(string $url): bool
    {
        $fullUrl = $this->baseUrl . $url;
        
        foreach (self::IGNORED_CAR_BRAND_URLS as $ignoredUrl) {
            if ($fullUrl === $ignoredUrl) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Generate spare parts categories for a given URL 
     */
    private function generateSparePartsCategoriesForUrl(string $url, int $parentId)
    {
        $this->info("=== Generating spare parts categories for: {$url} ===");
        
        try {
            $createdCategories = $this->sparePartsService->generateSparePartsCategories($url, $parentId);
            $this->info("=== Spare parts categories generation completed ===");
            $this->info("Created " . count($createdCategories) . " categories with parent relationship to ID: {$parentId}");
        } catch (\Exception $e) {
            $this->error("Failed to generate spare parts categories: " . $e->getMessage());
            Log::channel('worker')->error("Spare parts generation error for {$url}: " . $e->getMessage());
        }
    }


    private function getSparePartsDepth(string $url): int
    {
        $sparePartsPos = strpos($url, '/spare-parts/');
        if ($sparePartsPos === false) {
            return 0;
        }
        
        $afterSpareParts = substr($url, $sparePartsPos + strlen('/spare-parts/'));
        
        $afterSpareParts = rtrim($afterSpareParts, '/');
        
        if (empty($afterSpareParts)) {
            return 0;
        }
        
        $segments = explode('/', $afterSpareParts);
        
        $segments = array_filter($segments, function($segment) {
            return !empty($segment);
        });
        
        return count($segments);
    }
  
}
