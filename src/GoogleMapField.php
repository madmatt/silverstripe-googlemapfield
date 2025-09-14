<?php

/**
 * GoogleMapField
 *
 * Lets you record a precise location using latitude/longitude fields to a
 * DataObject. Displays a map using the Google Maps API. The user may then
 * choose where to place the marker; the landing coordinates are then saved.
 * You can also search for locations using the search box, which uses the Google
 * Maps Geocoding API.
 *
 * @author <@willmorgan>
 */

namespace BetterBrief;

use SilverStripe\Core\Environment;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\TextField;
use SilverStripe\View\Requirements;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\Forms\FieldList;
use SilverStripe\View\ArrayData;

class GoogleMapField extends CompositeField
{

    protected $data;

    protected $defaultValues = [];
    /**
     * @var FormField
     */
    protected $latField;

    /**
     * @var FormField
     */
    protected $lngField;

    /**
     * @var FormField
     */
    protected $zoomField;

    /**
     * @var FormField
     */
    protected $boundsField;

    /**
     * @var TextField
     */
    protected $searchField;

    protected ?string $restrictToCountry = null;

    protected string $restrictToTypes = '';

    /**
     * The merged version of the default and user specified options
     * @var array
     */
    protected $options = [];

    public function __construct($name, $title = null, $value = null, $data = [],  $options = [])
    {
        $this->children = new FieldList();
        $this->brokenOnConstruct = false;

        $this->setupOptions($options);

        FormField::__construct($name, $title, $value);

        if ($data) {
            $this->setDataRecord($data);
            $this->defaultValues = $data;
        }

        $this->addExtraClass('googlemapfield');

        $this->setupChildren();

    }


    public function setDataRecord($data)
    {
        $this->data = $data;
        $this->setupChildren();
        return $this;
    }


    public function hasData()
    {
        return true;
    }


    public function setValue($value, $data = null)
    {
        $this->setDataRecord($value);

        if (!$this->children->count()) {
            $this->setupChildren();
        }

        $this->latField->setValue($value['Latitude'] ?? '', $data);
        $this->lngField->setValue($value['Longitude'] ?? '', $data);
        $this->zoomField->setValue($value['Zoom'] ?? '', $data);
        $this->boundsField->setValue($value['Bounds'] ?? '', $data);
        $this->searchField->setValue($value['Search'] ?? '', $data);

        return parent::setValue($value, $data);
    }


    /**
     * Merge options preserving the first level of array keys
     *
     * @param array $options
     */
    public function setupOptions(array $options)
    {
        $this->options = static::config()->default_options;

        foreach ($this->options as $name => &$value) {
            if (isset($options[$name])) {
                if (is_array($value)) {
                    $value = array_merge($value, $options[$name]);
                } else {
                    $value = $options[$name];
                }
            }
        }
    }

    /**
     * Set up child hidden fields, and optionally the search box.
     */
    public function setupChildren()
    {
        $name = $this->getName();
        $visibleFields = $this->getOption('visible_fields');

        $getFieldType = function ($field) use ($visibleFields) {
            return $visibleFields && is_array($visibleFields) && in_array($field, $visibleFields)
                ? TextField::class
                : HiddenField::class;
        };

        // Create the latitude/longitude hidden fields
        $this->latField = $getFieldType('Latitude')::create(
            $name . '[Latitude]',
            'Lat',
            $this->recordFieldData('Latitude')
        )->addExtraClass('googlemapfield-latfield no-change-track mb-2');

        $this->lngField = $getFieldType('Longitude')::create(
            $name . '[Longitude]',
            'Lng',
            $this->recordFieldData('Longitude')
        )->addExtraClass('googlemapfield-lngfield no-change-track mb-2');

        $this->zoomField = $getFieldType('Zoom')::create(
            $name . '[Zoom]',
            'Zoom',
            $this->recordFieldData('Zoom')
        )->addExtraClass('googlemapfield-zoomfield no-change-track mb-2');

        $this->boundsField = $getFieldType('Bounds')::create(
            $name . '[Bounds]',
            'Bounds',
            $this->recordFieldData('Bounds')
        )->addExtraClass('googlemapfield-boundsfield no-change-track mb-2');

        $this->children = FieldList::create();
        $this->children->push($this->latField);
        $this->children->push($this->lngField);
        $this->children->push($this->zoomField);
        $this->children->push($this->boundsField);

        $this->searchField = TextField::create($name . '[Search]', $this->Title, $this->recordFieldData('Search'))
            ->addExtraClass('googlemapfield-searchfield')
            ->setAttribute('placeholder', 'Search for a location');

        if ($this->options['show_search_box']) {
            $this->children->push($this->searchField);
        }

        return $this;
    }


    /**
     * @param array $properties
     * @see https://developers.google.com/maps/documentation/javascript/reference
     * {@inheritdoc}
     */
    public function Field($properties = [])
    {
        $jsOptions = [
            'coords' => [
                $this->recordFieldData('Latitude'),
                $this->recordFieldData('Longitude')
            ],
            'center' => [
                $this->recordFieldData('Latitude') ?: $this->getOption('center.Latitude'),
                $this->recordFieldData('Longitude') ?: $this->getOption('center.Longitude'),
            ],
            'map' => [
                'zoom' => $this->recordFieldData('Zoom') ?: $this->getOption('map.zoom'),
                'mapTypeId' => 'ROADMAP',
            ],
        ];

        $this->setAttribute('data-settings', json_encode($jsOptions));
        $this->requireDependencies();

        return parent::Field($properties);
    }


    /**
     * Set up and include any frontend requirements
     * @return void
     */
    protected function requireDependencies()
    {
        $gmapsParams = array(
            'callback' => 'googlemapfieldInit',
            'libraries' => 'places'
        );

        if ($region = $this->getOption('region')) {
            $gmapsParams['region'] = $region;
        }

        if ($key = $this->getOption('api_key')) {
            $gmapsParams['key'] = $key;
        } elseif ($key = Environment::getEnv('GOOGLE_MAP_API_KEY')) {
            $gmapsParams['key'] = $key;
        }

        $this->extend('updateGoogleMapsParams', $gmapsParams);

        Requirements::css('betterbrief/silverstripe-googlemapfield: client/css/GoogleMapField.css');
        Requirements::javascript('betterbrief/silverstripe-googlemapfield: client/js/GoogleMapField.js');
        Requirements::javascript('//maps.googleapis.com/maps/api/js?' . str_replace('&amp;', '&', http_build_query($gmapsParams)), [
            'defer' => true,
        ]);
    }


    /**
     * Take the latitude/longitude fields and save them to the DataObject.
     * {@inheritdoc}
     */
    public function saveInto(DataObjectInterface $record)
    {
        $record->setCastedField($this->childFieldName('Latitude'), $this->latField->dataValue());
        $record->setCastedField($this->childFieldName('Longitude'), $this->lngField->dataValue());
        $record->setCastedField($this->childFieldName('Zoom'), $this->zoomField->dataValue());
        $record->setCastedField($this->childFieldName('Bounds'), $this->boundsField->dataValue());
        $record->setCastedField($this->childFieldName('Search'), $this->boundsField->dataValue());

        return $this;
    }


    protected function childFieldName($name): string
    {
        $fieldNames = $this->getOption('field_names');

        return isset($fieldNames[$name]) ? $fieldNames[$name]: $name;
    }


    protected function recordFieldData($name)
    {
        $fieldName = $this->childFieldName($name);

        if ($this->data) {
            if (is_array($this->data)) {
                $this->data = ArrayData::create($this->data);
            }
        }

        return ($this->data) ? $this->data->$fieldName : $this->getDefaultValue($name);
    }


    public function getDefaultValue($name)
    {
        $fieldValues = $this->defaultValues;

        if (empty($defaultValues)) {
            $fieldValues = $this->getOption('default_field_values');
        }

        return isset($fieldValues[$name]) ? $fieldValues[$name] : null;
    }


    public function getLatField(): ?TextField
    {
        return $this->latField;
    }


    public function getLngField(): ?TextField
    {
        return $this->lngField;
    }


    public function getZoomField(): ?TextField
    {
        return $this->zoomField;
    }


    public function getBoundsField(): ?TextField
    {
        return $this->boundsField;
    }

    public function getSearchField(): ?TextField
    {
        return $this->searchField;
    }


    public function getRestrictToCountry(): ?string
    {
        return $this->restrictToCountry;
    }


    public function setRestrictToCountry(?string $country)
    {
        $this->restrictToCountry = $country;

        return $this;
    }


    public function getRestrictToTypes(): string
    {
        return $this->restrictToTypes;
    }


    public function setRestrictToTypes(string $types)
    {
        $this->restrictToTypes = $types;

        return $this;
    }


    /**
     * Get the merged option that was set on __construct
     * @param string $name The name of the option
     * @return mixed
     */
    public function getOption($name)
    {
        // Quicker execution path for "."-free names
        if (strpos($name, '.') === false) {
            if (isset($this->options[$name])) {
                return $this->options[$name];
            }
        } else {
            $names = explode('.', $name);

            $var = $this->options;

            foreach ($names as $n) {
                if (!isset($var[$n])) {
                    return null;
                }
                $var = $var[$n];
            }

            return $var;
        }
    }

    /**
     * Set an option for this field
     * @param string $name The name of the option to set
     * @param mixed $val The value of said option
     * @return $this
     */
    public function setOption($name, $val)
    {
        // Quicker execution path for "."-free names
        if (strpos($name, '.') === false) {
            $this->options[$name] = $val;
        } else {
            $names = explode('.', $name);

            // We still want to do this even if we have strict path checking for legacy code
            $var = &$this->options;

            foreach ($names as $n) {
                $var = &$var[$n];
            }

            $var = $val;
        }

        return $this;
    }

    public function validate($validator)
    {
        $lat = $this->latField->dataValue();
        $lng = $this->lngField->dataValue();

        // If the lat/lng fields are the same as the default values, we don't need to validate
        if ($lat == $this->getDefaultValue('Latitude') && $lng == $this->getDefaultValue('Longitude')) {
            return false;
        }

        if ($this->Required() && (!$lat || !$lng) && !$this->searchField->dataValue()) {
            $name = strip_tags($this->Title() ? $this->Title() : $this->getName());
            $validator->validationError(
                $this->name,
                _t(
                    'BetterBrief\\GoogleMapField.VALIDATEMISSING',
                    '{name} is required',
                    ['name' => $name]
                ),
                "validation"
            );
            return false;
        }
        return true;
    }
}
