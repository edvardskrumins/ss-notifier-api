<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Filter;
use App\Models\FilterValue;
use Illuminate\Support\Facades\Log;
use App\Models\CategoryRelationship;

class SparePartsCategoryService
{
    /**
     * Filter values for specific filters
     */
    private const FILTER_VALUES = [
        'Gads' => [
            '2025' => '2025',
            '2024' => '2024',
            '2023' => '2023',
            '2022' => '2022',
            '2021' => '2021',
            '2020' => '2020',
            '2019' => '2019',
            '2018' => '2018',
            '2017' => '2017',
            '2016' => '2016',
            '2015' => '2015',
            '2014' => '2014',
            '2013' => '2013',
            '2012' => '2012',
            '2011' => '2011',
            '2010' => '2010',
            '2009' => '2009',
            '2008' => '2008',
            '2007' => '2007',
            '2006' => '2006',
            '2005' => '2005',
            '2004' => '2004',
            '2003' => '2003',
            '2002' => '2002',
            '2001' => '2001',
            '2000' => '2000',
            '1999' => '1999',
            '1998' => '1998',
            '1997' => '1997',
            '1996' => '1996',
            '1995' => '1995',
            '1994' => '1994',
            '1993' => '1993',
            '1992' => '1992',
            '1991' => '1991',
            '1990' => '1990',
            '1989' => '1989',
            '1987' => '1987',
            '1986' => '1986',
            '1985' => '1985',
            '1983' => '1983',
            '1981' => '1981',
        ],
        'Dzinējs' => [
            '493' => 'Benzīns',
            '495' => 'Benzīns/gāze',
            '494' => 'Dīzelis',
            '7434' => 'Hibrīds',
            '114330' => 'Elektriskais',
        ],
        'Stāv.' => [
            '6751' => 'jaun.',
            '6752' => 'lietota',
        ],
        'Darījuma veids' => [
            '/lv/transport/spare-parts/spare-parts-from-one-auto/' => 'Visi',
            '/lv/transport/spare-parts/spare-parts-from-one-auto/sell/' => 'Pārdod',
            '/lv/transport/spare-parts/spare-parts-from-one-auto/repair/' => 'Remonts',
        ],
    ];

    /**
     * Standard spare parts category names with their Latvian titles
     */
    private const SPARE_PARTS_CATEGORIES = [
        'spare-parts-from-one-auto' => 'Rezerves daļas no vienas a/m',
        'ignition-distributors' => 'Aizdedzes sadalītāji',
        'gear-boxes' => 'Ātrumkārbas',
        'pumps-the-brake' => 'Bremzes sūkņi',
        'tanks-brake-liquid' => 'Bremžu šķidruma tvertnes',
        'idling-regulators' => 'Brīvgaitas regulātori',
        'tubes' => 'Caurules',
        'fuel-filters' => 'Degvielas filtri',
        'fuel-pumps' => 'Degvielas sūkņi',
        'tanks-cooling-liquid' => 'Dzesēšanas šķidruma tvertnes',
        'pillows-engine' => 'Dzinēja spilveni',
        'engines' => 'Dzinēji',
        'oil-filters' => 'Eļļas filtri',
        'filters' => 'Filtri',
        'air-filters' => 'Gaisa filtri',
        'generators' => 'Ģeneratori',
        'rings' => 'Gredzeni',
        'hydro-amplifier' => 'Hidropastiprinātāji',
        'liners' => 'Ieliktņi',
        'carburetors' => 'Karburatori',
        'catalysts' => 'Katalizatori',
        'compressors' => 'Kompresori',
        'washer-tanks' => 'Mazgātāju tvertnes',
        'radiators' => 'Radiatori',
        'radiators-cooling-system' => 'Radiātori dzesēšanas sistēmai',
        'radiators-cooling-of-oil' => 'Radiātori eļļas dzesēšanai',
        'radiators-of-intercooler' => 'Radiātori interkūleram',
        'radiators-of-conditioner' => 'Radiātori kondicionētājam',
        'radiators-of-stove' => 'Radiātori krāsns',
        'reducers' => 'Reduktori',
        'distributors' => 'Sadalītāji',
        'clutches' => 'Sajūgs',
        'belts' => 'Siksnas',
        'sprays' => 'Smidzinātāji',
        'coils-for-ignition' => 'Spoles aizdedzei',
        'sprayers' => 'Sprauslas',
        'gaskets' => 'Starplikas',
        'starters' => 'Starteri',
        'pumps' => 'Sūkņi',
        'hydraulic-pumps' => 'Sūkņi hidrauliskie',
        'candles' => 'Sveces, vadi',
        'termosajugi' => 'Termosajugi',
        'thermostats' => 'Termostati',
        'hawsers' => 'Troses',
        'turbines' => 'Turbīnas',
        'valves' => 'Vārsti',
        'fans-for-radiator' => 'Ventilatori radiatoram',
        'fans-for-salon' => 'Ventilatori salonam',
        'pistons' => 'Virzuli',
        'accumulators' => 'Akumulatori',
        'rear-view-cameras' => 'Atpakaļskata kameras',
        'blocks' => 'Bloki',
        'computers' => 'Datori',
        'detectors' => 'Detektori',
        'electronics' => 'Elektronika',
        'light-sensors' => 'Gaismas sensori',
        'dashboards' => 'Instrumentu paneļi',
        'commutators' => 'Komutatori',
        'air-conditions' => 'Kondicionētāji',
        'chargers' => 'Lādētāji',
        'rain-sensors' => 'Lietus sensori',
        'headlamps-front' => 'Lukturi priekšējie',
        'headlamps-back' => 'Lukturi aizmugurējie',
        'headlamps-fog' => 'Lukturi miglas',
        'navigation-systems' => 'Navigācijas sistēmas',
        'pagrieziena-raditaji' => 'Pagrieziena rādītāji',
        'parking-sensors' => 'Parkošanās sensori',
        'radar-detectors' => 'Radaru detektori',
        'tire-pressure-sensors' => 'Riepu spiediena sensori',
        'sensors' => 'Sensori',
        'signallings' => 'Signalizācijas',
        'heaters' => 'Sildītāji',
        'lights-xenon' => 'Spuldzes, Xenon',
        'tachographs' => 'Tahogrāfi',
        'wires' => 'Vadi',
        'video-recorders' => 'Videoreģistratori',
        'probes' => 'Zondes',
        'ignition-locks' => 'Aizdedzes slēdzenes',
        'underwings-back-right-airbags' => 'Apakšspārni aizmugurējie, labie',
        'underwings-back-left' => 'Apakšspārni aizmugurējie, kreisie',
        'underwings-front-left' => 'Apakšspārni priekšējie, kreisie',
        'underwings-front-right' => 'Apakšspārni priekšējie, labie',
        'luggage-racks' => 'Bagāžnieki',
        'bumpers-back' => 'Bamperi aizmugurējie',
        'bumpers-front' => 'Bamperi priekšējie',
        'fuel-tanks' => 'Degvielas tvertnes',
        'seatbelts' => 'Drošības jostas',
        'airbags' => 'Drošības spilveni',
        'fenders' => 'Dubļu sargi',
        'doors-lefts-front' => 'Durvis kreisās, priekšējās',
        'doors-lefts-back' => 'Durvis kreisās, aizmugurējās',
        'doors-rights-back' => 'Durvis labās, aizmugurējās',
        'doors-rights-front' => 'Durvis labās, priekšējās',
        'door-handles' => 'Durvju rokturi',
        'door-locks' => 'Durvju slēdzenes',
        'door-glasses' => 'Durvju stikli',
        'emblems' => 'Emblēmas',
        'inserts-moldings' => 'Ielikņi, moldingi',
        'mufflers' => 'Izpūtēji',
        'roof-racks' => 'Jumta bagāžnieki',
        'crankcase-protection' => 'Kartera aizsardzība',
        'lambda-sensors' => 'Lambda zondes',
        'arcs' => 'Loki',
        'sunroofs' => 'Lūki',
        'washer-pumps' => 'Mazgātāja sūkni',
        'hoods-front' => 'Pārsegi',
        'hooks' => 'Piekabes aķi',
        'roof-rack' => 'Reliņi',
        'front-grates' => 'Restes priekšējas',
        'salons' => 'Saloni',
        'thresholds' => 'Sānu sliekšņi',
        'seats-back' => 'Sēdekli aizmugurējie',
        'seats-front' => 'Sēdekli priekšējie',
        'wings-back-right' => 'Spārni aizmugurējie, labie',
        'wings-back-left' => 'Spārni aizmugurējie, kreisie',
        'wings-front-left' => 'Spārni priekšējie, kreisie',
        'wings-front-right' => 'Spārni priekšējie, labie',
        'mirrors-left-in-complete' => 'Spoguļi kreisie komplektā',
        'mirrors-left-only-glass' => 'Spoguļi kreisie (tikai stikls)',
        'mirrors-right-only-glass' => 'Spoguļi labie (tikai stikls)',
        'mirrors-right-in-complete' => 'Spoguļi labie komplektā',
        'mirrors-rearview' => 'Spoguļi, atpakaļskata',
        'spoilers' => 'Spoileri',
        'glass-lifts' => 'Stikla pacēlāji',
        'glasses-back' => 'Stikli aizmugurējie',
        'steering-wheels' => 'Stūres',
        'caps-of-tank' => 'Vāciņi tvertnei',
        'windshields' => 'Vējstikli',
        'windshield-wipers-brushes' => 'Vējstiklu tīrītāji, slotiņas',
        'winches' => 'Vinčas',
        'bodies' => 'Virsbūves',
        'shock-absorbers-back' => 'Amortizatori aizmugurējie',
        'dampers-of-luggage-rack' => 'Amortizātori bagāžniekam',
        'dampers-of-hood' => 'Amortizātori pārsegam',
        'shock-absorbers-front' => 'Amortizatori priekšējie',
        'springs-shock-absorber-front' => 'Amortizātoru atsperes, priekšējas',
        'springs-shock-absorber-back' => 'Amortizātoru atsperes, aizmugurējas',
        'reliances' => 'Balasti',
        'brake-discs-back' => 'Bremžu diski, aizmugurējie',
        'brake-discs-front' => 'Bremžu diski, priekšējie',
        'brake-pads-back' => 'Bremžu kluči, aizmugurējie',
        'brake-pads-front' => 'Bremžu kluči, priekšējie',
        'boots' => 'Dekoratīvie diski',
        'sensors-abs' => 'Devēji ABS',
        'grenades' => 'Granatas',
        'bearings' => 'Gultņi',
        'cardans' => 'Kardāni',
        'rail' => 'Līstes',
        'ball-joints' => 'Lodbalsti',
        'semiaxis' => 'Pusasi',
        'anthers' => 'Putekļuizsargi',
        'hubs' => 'Rumbas',
        'bearings-of-hub' => 'Rumbas gultņi',
        'hinges' => 'Šarniri',
        'racks-back' => 'Statņi aizmugurējie',
        'racks-front' => 'Statņi priekšējie',
        'steering-mechanisms' => 'Stūres mehānismi',
        'supports' => 'Suporti',
        'levers' => 'Sviras',
        'axles-front' => 'Tilti priekšējie',
        'axles-back' => 'Tilts aizmugurējie',
        'tractions' => 'Vilces',
        'other-spare-parts' => 'Citas rezerves daļas'
    ];

    /**
     * Generate spare parts categories for a given base URL
     */
    public function generateSparePartsCategories(string $baseUrl, ?int $parentId = null, string $locale = Category::LOCALE_LV): array
    {
        $createdCategories = [];

        $baseUrl = rtrim($baseUrl, '/') . '/';

        foreach (self::SPARE_PARTS_CATEGORIES as $categoryName => $title) {
            $fullUrl = $baseUrl . $categoryName . '/';
            
            $category = Category::withoutLocaleScope()->updateOrCreate(
                [
                    'locale' => $locale,
                    'url' => $fullUrl
                ],
                [
                    'title' => $title,
                    'type' => Category::TYPE_ADS,
                    'locale' => $locale,
                ]
            );
            
            if ($parentId) {
                CategoryRelationship::updateOrCreate([
                    'parent_id' => $parentId,
                    'child_id' => $category->id,
                ]);
            }
            
            $createdCategories[] = $category;
            
        }

        $this->generateStandardSparePartsFilters($createdCategories);
        
        return $createdCategories;
    }

    /**
     * Generate standard filters for spare parts categories
     */
    private function generateStandardSparePartsFilters(array $categories): void
    {
        $standardFilters = [
            [
                'label' => 'Cena',
                'type' => Filter::TYPE_CUSTOM_RANGE,
                'form_param' => 'topt[8]',
                'max_length' => null,
            ],
            [
                'label' => 'Gads',
                'type' => Filter::TYPE_SELECT_RANGE,
                'form_param' => 'topt[18]',
                'max_length' => null,
            ],
            [
                'label' => 'Tilpums',
                'type' => Filter::TYPE_CUSTOM_RANGE,
                'form_param' => 'topt[15]',
                'max_length' => null,
            ],
            [
                'label' => 'Dzinējs',
                'type' => Filter::TYPE_SELECT,
                'form_param' => 'opt[34]',
                'max_length' => null,
            ],
            [
                'label' => 'Detaļas numurs',
                'type' => Filter::TYPE_CUSTOM_TEXT,
                'form_param' => 'topt[1735]',
                'max_length' => 70,
            ],
            [
                'label' => 'Stāv.',
                'type' => Filter::TYPE_SELECT,
                'form_param' => 'opt[352]',
                'max_length' => null,
            ],
            [
                'label' => 'Darījuma veids',
                'type' => Filter::TYPE_SELECT,
                'form_param' => 'sid',
                'max_length' => null,
            ],
        ];

        foreach ($categories as $category) {
            foreach ($standardFilters as $filterData) {
                $filter = Filter::updateOrCreate(
                    [
                        'label' => $filterData['label'],
                        'category_id' => $category->id,
                    ],
                    [
                        'label' => $filterData['label'],
                        'category_id' => $category->id,
                        'type' => $filterData['type'],
                        'form_param' => $filterData['form_param'],
                        'max_length' => $filterData['max_length'],
                    ]
                );
                
                $this->generateFilterValues($filter);
            }
        }
    }

    /**
     * Generate filter values for specific filters
     */
    private function generateFilterValues(Filter $filter): void
    {
        if (!isset(self::FILTER_VALUES[$filter->label])) {
            return;
        }

        $filterValues = self::FILTER_VALUES[$filter->label];
        
        foreach ($filterValues as $value => $label) {
            FilterValue::updateOrCreate(
                [
                    'filter_id' => $filter->id,
                    'value' => $value,
                ],
                [
                    'filter_id' => $filter->id,
                    'value' => $value,
                    'label' => $label,
                ]
            );
        }
    }

}
