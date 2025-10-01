<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    private $maxDepth = 10;
    private $client;
    private $visitedUrls = [];
    private $categoryStructure = [];

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
        $this->info("Starting to crawl ss.com from: {$this->baseUrl}");
        $this->info("Maximum depth: {$this->maxDepth}");
        
        $this->scrapeHomePage();

        $this->info('Crawling completed!');
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
        $title = $crawler->filter('title')->text();
        return $title ?: 'Unknown Title';
    }

    private function scrapeHomePage()
    {
        $response = $this->client->get($this->baseUrl . '/' . $this->locale);

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
            
            $this->categoryStructure[$category['text']] = [
                'href' => $category['href'],
                'title' => $category['title'],
                'subcategories' => []
            ];
            
            // Crawl subcategories recursively
            $subcategories = $this->crawlCategoryPage($category['href'], 0);

            $this->categoryStructure[$category['text']]['subcategories'] = $subcategories;
        }
    }


    /**
     * Crawl a category page and its subcategories
     */
    private function crawlCategoryPage(string $url, int $depth): array
    {
        if (in_array($url, $this->visitedUrls) || $depth > $this->maxDepth) {
            return [];
        }

        $this->visitedUrls[] = $url;
        $this->info("Crawling category page: {$url} (depth: {$depth})");

        try {
            $response = $this->client->get($this->baseUrl . $url);
            $crawler = new Crawler($response->getBody()->getContents());

            // Check if this is an ads page
            if ($crawler->filter('#head_line')->count() > 0) {
                $this->info("  -> Ads page detected, extracting filters");
                dump($this->extractAdsFilters($crawler, $url));
                return [
                    'type' => 'ads',
                    'ads' => $this->extractAdsFilters($crawler, $url)
                ];
            }

            // Extract subcategories
            $categories = $crawler->filter('.a_category')->each(function (Crawler $node) {
                return [
                    'href' => $node->attr('href'),
                    'title' => $node->attr('title'),
                    'text' => $node->text()
                ];
            });

            $this->info("  -> Found " . count($categories) . " subcategories");

            $subcategories = [];
            
            foreach ($categories as $category) {
                if ($category['href']) {
                    $this->info("    - {$category['text']} ({$category['href']})");
                    
                    // Recursively crawl subcategory as there can be multiple
                    $subcategoryStructure = $this->crawlCategoryPage($category['href'], $depth + 1);
                    
                    if (isset($subcategoryStructure['type']) && $subcategoryStructure['type'] === 'ads') {
                        $subcategories[] = [
                            'text' => $category['text'],
                            'href' => $category['href'],
                            'title' => $category['title'],
                            'ads' => $subcategoryStructure['ads']
                        ];
                    } else {
                        $subcategories[] = [
                            'text' => $category['text'],
                            'href' => $category['href'],
                            'title' => $category['title'],
                            'subcategories' => $subcategoryStructure
                        ];
                    }
                    
                    usleep(1 * 100000); 
                }
            }

            return $subcategories;

        } catch (RequestException $e) {
            $this->error("Failed to crawl category page {$url}: " . $e->getMessage());
            Log::error("Crawl error for {$url}: " . $e->getMessage());
            return [];
        }
    }

    private function extractAdsFilters(Crawler $crawler, string $url): array
    {
        $filters = [
            'url' => $url,
            'title' => $this->extractPageTitle($crawler),
            'filters' => []
        ];

        $allFilters = $this->extractFilters($crawler);
        $filters['filters'] = $this->groupFiltersByTypeAndLabel($allFilters);

        return $filters;
    }

    private function groupFiltersByTypeAndLabel(array $allFilters): array
    {
        $filters = [];

        foreach ($allFilters as $filter) {
            $filterType = $filter['type'];
            if (!isset($filters['filters'][$filterType])) {
                $filters['filters'][$filterType] = [];
            }
            $filters['filters'][$filterType][$filter['label']] = $filter;
        }

        return $filters;
    }


    private function extractFilters(Crawler $crawler): array
    {
        $firstLineFilters = $this->extractFirstLineFilters($crawler);
        $secondLineFilters = $this->extractSecondLineFilters($crawler);

        return array_merge($firstLineFilters, $secondLineFilters);
    }

    private function classifyAndExtractFilter(Crawler $cell, string $label): ?array
    {
        // Check for custom_range: text inputs with min/max
        $minInput = $cell->filter('input[type="text"][name$="[min]"]');
        $maxInput = $cell->filter('input[type="text"][name$="[max]"]');
        
        if ($minInput->count() > 0 || $maxInput->count() > 0) {
            $name = $this->extractFieldName($minInput->count() > 0 ? $minInput : $maxInput);
            return [
                'type' => 'custom_range',
                'name' => $name,
                'label' => $label,
                'input_type' => 'text',
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
            
            return [
                'type' => 'select_range',
                'name' => $name,
                'label' => $label,
                'input_type' => 'select',
                'min_options' => $minOptions,
                'max_options' => $maxOptions
            ];
        }

        // Check for custom_text: single text input without min/max
        $textInput = $cell->filter('input[type="text"]:not([name$="[min]"]):not([name$="[max]"])');
        if ($textInput->count() > 0) {
            $name = $textInput->attr('name');
            return [
                'type' => 'custom_text',
                'name' => $name,
                'label' => $label,
                'input_type' => 'text',
                'maxlength' => $textInput->attr('maxlength'),
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
                    ? 'form_select' 
                    : 'select';
                
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

    private function extractFirstLineFilters(Crawler $crawler): array
    {
        $filters = [];

        $filterRows = $crawler->filter('#filter_tbl tr');

        $filterRows->each(function (Crawler $row) use (&$filters) {
            $filterCells = $row->filter('td.filter_name');

            $filterCells->each(function (Crawler $cell) use (&$filters) {
                $label = $this->extractFilterLabel($cell);
                if (!$label) return;
                
                // Classify and extract the filter based on its structure
                $filter = $this->classifyAndExtractFilter($cell, $label);

                if ($filter) {
                    $filters[] = $filter;
                }
            });
        });

         return $filters;
    }
    
    private function extractSecondLineFilters(Crawler $crawler): array
    {
        $filters = [];

        $filterRows = $crawler->filter('.filter_second_line_dv');

        $filterRows->each(function (Crawler $row) use (&$filters) {
            $filterCells = $row->filter('span.filter_opt_dv');

            $filterCells->each(function (Crawler $cell) use (&$filters) {
                $label = $this->extractFilterLabel($cell);
                if (!$label) return;
                
                // Classify and extract the filter based on its structure
                $filter = $this->classifyAndExtractFilter($cell, $label);

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
                'value' => $node->attr('value'),
                'text' => trim($node->text()),
            ];
        });
    }
  
}
