/**
 * GoogleMapField.js
 * @author <@willmorgan>
 */

let gmapsAPILoaded = false;
let gmapsReady = null;

window.googlemapfieldInit = function () {
    gmapsAPILoaded = true;

    if (gmapsReady !== null) {
        gmapsReady();
    }
};

(function ($) {
    // Run this code for every googlemapfield
    function initField() {
        var field = $(this);

        if (field.data("gmapfield-inited") === true) {
            return;
        }

        field.data("gmapfield-inited", true);

        var settings = JSON.parse(field.attr("data-settings"));

        const centre = new google.maps.LatLng(
            settings.center[0],
            settings.center[1]
        );

        const mapSettings = {
            streetViewControl: false,
            zoom: settings.map.zoom * 1,
            center: centre,
            mapTypeId: google.maps.MapTypeId[settings.map.mapTypeId],
        };

        const mapElement = field.find(".googlemapfield-map");

        const map = new google.maps.Map(mapElement[0], mapSettings);

        const marker = new google.maps.Marker({
            position: map.getCenter(),
            map: map,
            title: "Position",
            draggable: true,
        });

        const latField = field.find(".googlemapfield-latfield");
        const lngField = field.find(".googlemapfield-lngfield");
        const zoomField = field.find(".googlemapfield-zoomfield");
        const boundsField = field.find(".googlemapfield-boundsfield");
        const search = field.find(".googlemapfield-searchfield");

        // Update the hidden fields and mark as changed
        function updateField(latLng, init) {
            var latCoord = latLng.lat(),
                lngCoord = latLng.lng();

            mapSettings.coords = [latCoord, lngCoord];

            updateBounds(init);

            // Mark form as changed if this isn't initialisation
            if (!init) {
                latField.val(latCoord);
                lngField.val(lngCoord);

                $(".cms-edit-form").addClass("changed");
            }
        }

        function updateZoom(init) {
            zoomField.val(map.getZoom());

            // Mark form as changed if this isn't initialisation
            if (!init) {
                $(".cms-edit-form").addClass("changed");
            }
        }

        function updateBounds() {
            var bounds = JSON.stringify(map.getBounds().toJSON());
            boundsField.val(bounds);
        }

        function zoomChanged() {
            updateZoom();
            updateBounds();
        }

        function centreOnMarker() {
            var center = marker.getPosition();
            map.panTo(center);
            updateField(center);
        }

        function mapClicked(ev) {
            var center = ev.latLng;
            marker.setPosition(center);

            centreOnMarker();

            // update the address field if the checkbox is checked
            if (
                field.find(".googlemapfield-updateaddress input").is(":checked")
            ) {
                // find the nearest address
                var geocoder = new google.maps.Geocoder();
                geocoder.geocode(
                    { latLng: center },
                    function (results, status) {
                        if (status == google.maps.GeocoderStatus.OK) {
                            if (results[0]) {
                                search.val(results[0].formatted_address);
                            }
                        } else {
                            console.warn("Geocoding failed: " + status);
                        }
                    }
                );
            }
        }

        google.maps.event.addListener(map, "click", mapClicked);

        function geoSearchComplete(result, status) {
            if (status !== google.maps.GeocoderStatus.OK) {
                console.warn("Geocoding search failed");
                return;
            }
            marker.setPosition(result[0].geometry.location);
            centreOnMarker();
        }

        const restrictions = {};

        if (field.data("restrict-country")) {
            restrictions.country = field
                .data("restrict-country")
                .split(",")
                .map((country) => {
                    return country.trim();
                });
        }

        if (field.data("restrict-types")) {
            restrictions.types = field
                .data("restrict-types")
                .split(",")
                .map((type) => {
                    return type.trim();
                });
        }

        if (search) {
            // Create the autocomplete object, restricting the search to geographical
            // location types.
            var autocomplete = new google.maps.places.Autocomplete(
                search.get(0)
            );

            if (Object.keys(restrictions).length) {
                autocomplete.setComponentRestrictions(restrictions);
            }

            // When the user selects an address from the dropdown, populate the address
            // fields in the form.
            autocomplete.addListener("place_changed", function () {
                var place = autocomplete.getPlace();
                console.log("place changed", { place, restrictions });
                if (place && place.geometry) {
                    // chck to see if the
                    search.val(place.formatted_address);
                    marker.setPosition(place.geometry.location);
                    updateField(place.geometry.location, false);
                    centreOnMarker();
                }
            });
        }

        // Populate the fields to the current centre
        google.maps.event.addListenerOnce(map, "idle", function () {
            updateField(map.getCenter(), true);
            updateZoom(init);
        });

        google.maps.event.addListener(marker, "dragend", function (evt) {
            centreOnMarker();

            if (latField) {
                latField.val(evt.latLng.lat().toFixed(5));
            }

            if (lngField) {
                lngField.val(evt.latLng.lng().toFixed(5));
            }

            // if the googlemapfield-updateaddress input is checked then update the address
            if (
                field.find(".googlemapfield-updateaddress input").is(":checked")
            ) {
                // find the nearest address
                var geocoder = new google.maps.Geocoder();

                geocoder.geocode(
                    { latLng: evt.latLng },
                    function (results, status) {
                        if (status == google.maps.GeocoderStatus.OK) {
                            if (results[0]) {
                                search.val(results[0].formatted_address);
                            }
                        } else {
                            console.warn("Geocoding failed: " + status);
                        }
                    }
                );
            }
        });

        google.maps.event.addListener(map, "zoom_changed", zoomChanged);
    }

    $.fn.gmapfield = function () {
        return this.each(function () {
            initField.call(this);
        });
    };

    function init() {
        var mapFields = $(".googlemapfield").gmapfield();

        mapFields.each(initField);
    }

    // CMS stuff: set the init method to re-run if the page is saved or pjaxed
    // there are no docs for the CMS implementation of entwine, so this is hacky
    if (!!$.fn.entwine && $(document.body).hasClass("cms")) {
        (function setupCMS() {
            var matchFunction = function () {
                if (gmapsAPILoaded) {
                    init();
                }
            };
            $.entwine("googlemapfield", function ($) {
                $(".cms-tabset").entwine({
                    onmatch: matchFunction,
                });
                $(".cms-tabset-nav-primary li").entwine({
                    onclick: matchFunction,
                });
                $(".ss-tabset li").entwine({
                    onclick: matchFunction,
                });
                $(".cms-edit-form").entwine({
                    onmatch: matchFunction,
                });
            });
        })();
    }

    if (gmapsAPILoaded) {
        init();
    }

    gmapsReady = function () {
        init();
    };
})(jQuery);
