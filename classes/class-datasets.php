<?php
namespace SchemaPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * lists nobody should have to type.
 *
 * A select can take its choices from one of these instead of a hand-written
 * list. That is not only convenience: a country list typed by hand is a country
 * list that is missing three countries, spells one of them differently from the
 * next collection, and stores "USA" where its neighbour stores "United States".
 *
 * A field stores only which dataset it uses, never a copy of it. The options
 * are resolved when they are read, so a correction here reaches every field
 * that pointed at it — which is the whole reason for naming a list rather than
 * pasting one.
 *
 * Values are the stable codes, not the labels: ISO 3166-1 alpha-2 for
 * countries, USPS abbreviations for states. What is stored survives a label
 * being reworded, and is what an API or a shipping form actually wants.
 */
class Datasets
{
    /**
     * "CODE|Label" pairs, kept compact because these lists are long and their
     * shape is the least interesting thing about them.
     */
    const COUNTRIES = 'AF|Afghanistan,AX|Åland Islands,AL|Albania,DZ|Algeria,AS|American Samoa,AD|Andorra,AO|Angola,AI|Anguilla,AQ|Antarctica,AG|Antigua and Barbuda,AR|Argentina,AM|Armenia,AW|Aruba,AU|Australia,AT|Austria,AZ|Azerbaijan,BS|Bahamas,BH|Bahrain,BD|Bangladesh,BB|Barbados,BY|Belarus,BE|Belgium,BZ|Belize,BJ|Benin,BM|Bermuda,BT|Bhutan,BO|Bolivia,BQ|Bonaire, Sint Eustatius and Saba,BA|Bosnia and Herzegovina,BW|Botswana,BV|Bouvet Island,BR|Brazil,IO|British Indian Ocean Territory,BN|Brunei Darussalam,BG|Bulgaria,BF|Burkina Faso,BI|Burundi,CV|Cabo Verde,KH|Cambodia,CM|Cameroon,CA|Canada,KY|Cayman Islands,CF|Central African Republic,TD|Chad,CL|Chile,CN|China,CX|Christmas Island,CC|Cocos (Keeling) Islands,CO|Colombia,KM|Comoros,CG|Congo,CD|Congo, Democratic Republic of the,CK|Cook Islands,CR|Costa Rica,CI|Côte d’Ivoire,HR|Croatia,CU|Cuba,CW|Curaçao,CY|Cyprus,CZ|Czechia,DK|Denmark,DJ|Djibouti,DM|Dominica,DO|Dominican Republic,EC|Ecuador,EG|Egypt,SV|El Salvador,GQ|Equatorial Guinea,ER|Eritrea,EE|Estonia,SZ|Eswatini,ET|Ethiopia,FK|Falkland Islands,FO|Faroe Islands,FJ|Fiji,FI|Finland,FR|France,GF|French Guiana,PF|French Polynesia,TF|French Southern Territories,GA|Gabon,GM|Gambia,GE|Georgia,DE|Germany,GH|Ghana,GI|Gibraltar,GR|Greece,GL|Greenland,GD|Grenada,GP|Guadeloupe,GU|Guam,GT|Guatemala,GG|Guernsey,GN|Guinea,GW|Guinea-Bissau,GY|Guyana,HT|Haiti,HM|Heard Island and McDonald Islands,VA|Holy See,HN|Honduras,HK|Hong Kong,HU|Hungary,IS|Iceland,IN|India,ID|Indonesia,IR|Iran,IQ|Iraq,IE|Ireland,IM|Isle of Man,IL|Israel,IT|Italy,JM|Jamaica,JP|Japan,JE|Jersey,JO|Jordan,KZ|Kazakhstan,KE|Kenya,KI|Kiribati,KP|Korea, Democratic People’s Republic of,KR|Korea, Republic of,KW|Kuwait,KG|Kyrgyzstan,LA|Lao People’s Democratic Republic,LV|Latvia,LB|Lebanon,LS|Lesotho,LR|Liberia,LY|Libya,LI|Liechtenstein,LT|Lithuania,LU|Luxembourg,MO|Macao,MG|Madagascar,MW|Malawi,MY|Malaysia,MV|Maldives,ML|Mali,MT|Malta,MH|Marshall Islands,MQ|Martinique,MR|Mauritania,MU|Mauritius,YT|Mayotte,MX|Mexico,FM|Micronesia,MD|Moldova,MC|Monaco,MN|Mongolia,ME|Montenegro,MS|Montserrat,MA|Morocco,MZ|Mozambique,MM|Myanmar,NA|Namibia,NR|Nauru,NP|Nepal,NL|Netherlands,NC|New Caledonia,NZ|New Zealand,NI|Nicaragua,NE|Niger,NG|Nigeria,NU|Niue,NF|Norfolk Island,MK|North Macedonia,MP|Northern Mariana Islands,NO|Norway,OM|Oman,PK|Pakistan,PW|Palau,PS|Palestine, State of,PA|Panama,PG|Papua New Guinea,PY|Paraguay,PE|Peru,PH|Philippines,PN|Pitcairn,PL|Poland,PT|Portugal,PR|Puerto Rico,QA|Qatar,RE|Réunion,RO|Romania,RU|Russian Federation,RW|Rwanda,BL|Saint Barthélemy,SH|Saint Helena,KN|Saint Kitts and Nevis,LC|Saint Lucia,MF|Saint Martin,PM|Saint Pierre and Miquelon,VC|Saint Vincent and the Grenadines,WS|Samoa,SM|San Marino,ST|Sao Tome and Principe,SA|Saudi Arabia,SN|Senegal,RS|Serbia,SC|Seychelles,SL|Sierra Leone,SG|Singapore,SX|Sint Maarten,SK|Slovakia,SI|Slovenia,SB|Solomon Islands,SO|Somalia,ZA|South Africa,GS|South Georgia and the South Sandwich Islands,SS|South Sudan,ES|Spain,LK|Sri Lanka,SD|Sudan,SR|Suriname,SJ|Svalbard and Jan Mayen,SE|Sweden,CH|Switzerland,SY|Syrian Arab Republic,TW|Taiwan,TJ|Tajikistan,TZ|Tanzania,TH|Thailand,TL|Timor-Leste,TG|Togo,TK|Tokelau,TO|Tonga,TT|Trinidad and Tobago,TN|Tunisia,TR|Türkiye,TM|Turkmenistan,TC|Turks and Caicos Islands,TV|Tuvalu,UG|Uganda,UA|Ukraine,AE|United Arab Emirates,GB|United Kingdom,US|United States,UM|United States Minor Outlying Islands,UY|Uruguay,UZ|Uzbekistan,VU|Vanuatu,VE|Venezuela,VN|Viet Nam,VG|Virgin Islands, British,VI|Virgin Islands, U.S.,WF|Wallis and Futuna,EH|Western Sahara,YE|Yemen,ZM|Zambia,ZW|Zimbabwe';

    const US_STATES = 'AL|Alabama,AK|Alaska,AZ|Arizona,AR|Arkansas,CA|California,CO|Colorado,CT|Connecticut,DE|Delaware,DC|District of Columbia,FL|Florida,GA|Georgia,HI|Hawaii,ID|Idaho,IL|Illinois,IN|Indiana,IA|Iowa,KS|Kansas,KY|Kentucky,LA|Louisiana,ME|Maine,MD|Maryland,MA|Massachusetts,MI|Michigan,MN|Minnesota,MS|Mississippi,MO|Missouri,MT|Montana,NE|Nebraska,NV|Nevada,NH|New Hampshire,NJ|New Jersey,NM|New Mexico,NY|New York,NC|North Carolina,ND|North Dakota,OH|Ohio,OK|Oklahoma,OR|Oregon,PA|Pennsylvania,RI|Rhode Island,SC|South Carolina,SD|South Dakota,TN|Tennessee,TX|Texas,UT|Utah,VT|Vermont,VA|Virginia,WA|Washington,WV|West Virginia,WI|Wisconsin,WY|Wyoming,PR|Puerto Rico,VI|U.S. Virgin Islands,GU|Guam,AS|American Samoa,MP|Northern Mariana Islands';

    const CA_PROVINCES = 'AB|Alberta,BC|British Columbia,MB|Manitoba,NB|New Brunswick,NL|Newfoundland and Labrador,NS|Nova Scotia,NT|Northwest Territories,NU|Nunavut,ON|Ontario,PE|Prince Edward Island,QC|Quebec,SK|Saskatchewan,YT|Yukon';

    const AU_STATES = 'ACT|Australian Capital Territory,NSW|New South Wales,NT|Northern Territory,QLD|Queensland,SA|South Australia,TAS|Tasmania,VIC|Victoria,WA|Western Australia';

    const MONTHS = '1|January,2|February,3|March,4|April,5|May,6|June,7|July,8|August,9|September,10|October,11|November,12|December';

    const WEEKDAYS = '1|Monday,2|Tuesday,3|Wednesday,4|Thursday,5|Friday,6|Saturday,7|Sunday';

    /**
     * every dataset, keyed by the slug a field stores.
     *
     * @return array
     */
    public static function all()
    {
        static $sets = null;

        if ($sets !== null) {
            return $sets;
        }

        $sets = [
            'countries' => [
                'label' => __('Countries', 'schemapress'),
                'options' => self::parse(self::COUNTRIES),
            ],
            'us_states' => [
                'label' => __('US states and territories', 'schemapress'),
                'options' => self::parse(self::US_STATES),
            ],
            'ca_provinces' => [
                'label' => __('Canadian provinces', 'schemapress'),
                'options' => self::parse(self::CA_PROVINCES),
            ],
            'au_states' => [
                'label' => __('Australian states', 'schemapress'),
                'options' => self::parse(self::AU_STATES),
            ],
            'months' => [
                'label' => __('Months', 'schemapress'),
                'options' => self::parse(self::MONTHS),
            ],
            'weekdays' => [
                'label' => __('Days of the week', 'schemapress'),
                'options' => self::parse(self::WEEKDAYS),
            ],
        ];

        /**
         * filters the datasets a select may draw its choices from.
         *
         * a site with its own closed vocabulary — departments, regions, product
         * lines — registers it here and it becomes available to every select,
         * with the same guarantee: stored once, corrected in one place.
         *
         * @param array $sets slug => ['label' => string, 'options' => array]
         */
        $sets = apply_filters('schemapress/datasets', $sets);

        return $sets;
    }

    /**
     * whether a slug names a dataset.
     *
     * @param string $slug
     *
     * @return boolean
     */
    public static function exists($slug)
    {
        return is_string($slug) && $slug !== '' && isset(self::all()[$slug]);
    }

    /**
     * one dataset's options, or an empty list.
     *
     * @param string $slug
     *
     * @return array
     */
    public static function options($slug)
    {
        return self::exists($slug) ? self::all()[$slug]['options'] : [];
    }

    /**
     * the choices a select field offers, from whichever source it names.
     *
     * the one place that answers this, so the sanitizer, the resolver and the
     * admin cannot disagree about what a field allows.
     *
     * @param array $field
     *
     * @return array
     */
    public static function forField(array $field)
    {
        $source = $field['config']['source'] ?? '';

        if (self::exists($source)) {
            return self::options($source);
        }

        return isset($field['config']['options']) && is_array($field['config']['options'])
            ? $field['config']['options']
            : [];
    }

    /**
     * the registry as the admin needs it: labels for the picker, options so a
     * control can render without another request.
     *
     * @return array
     */
    public static function forClient()
    {
        $sets = [];

        foreach (self::all() as $slug => $set) {
            $sets[] = [
                'slug' => $slug,
                'label' => $set['label'],
                'options' => $set['options'],
            ];
        }

        return $sets;
    }

    /**
     * expands a compact "CODE|Label,CODE|Label" string.
     *
     * @param string $packed
     *
     * @return array
     */
    private static function parse($packed)
    {
        $options = [];

        // split on a comma only where a code and its pipe follow, because
        // several labels contain one of their own — "Korea, Republic of",
        // "Virgin Islands, British" — and splitting on every comma would turn
        // each of those into two broken entries
        foreach (preg_split('/,(?=[A-Za-z0-9]{1,3}\|)/', $packed) as $pair) {
            $parts = explode('|', $pair, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $options[] = ['value' => $parts[0], 'label' => $parts[1]];
        }

        return $options;
    }
}
