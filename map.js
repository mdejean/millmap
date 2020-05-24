"use strict";

proj4.defs('FIPS:3104','+proj=lcc +lat_1=40.66666666666666 +lat_2=41.03333333333333 +lat_0=40.16666666666666 +lon_0=-74 +x_0=300000 +y_0=0 +ellps=GRS80 +datum=NAD83 +to_meter=0.3048006096012192 +no_defs ');

function point_to_latlng(p) {
    p.x = Number.parseInt(p.x);
    p.y = Number.parseInt(p.y);
    p = proj4('FIPS:3104', 'WGS84', p);
    return L.latLng(p.y, p.x);
}

function points_to_polyline(points) {
    let lls = [];
    for (let point of points) {
        lls.push(point_to_latlng(point));
    }
    return L.polyline(lls);;
}


var options = {
  year: 'numeric', month: 'short', day: 'numeric', weekday: 'short', timeZone: 'UTC'
};
var date_formatter = new Intl.DateTimeFormat('en-US', options);

function format_date(s) {
    return date_formatter.format(new Date(Number.parseInt(s) * 1000))
}

function popup(actions) {
    let s = "<table>";
    for (let action of actions) {
        s += "<tr><td>";
        if (action.is_milling) {
            s += "<b>Milling</b>";
        } else {
            s += "<b>Paving</b>";
        }
        s += "</td><td>";
        s += format_date(action.action_date);
        s += "</td><td>";
        s += action.on_street;
        s += "</td><td>(";
        s += action.from_street;
        s += " - ";
        s += action.to_street;
        s += ")</td></tr>";
    }
    s += "</table>";
    return s;
}

function range(start, stop, step) {
    if (typeof stop == 'undefined') {
        // one param defined
        stop = start;
        start = 0;
    }

    if (typeof step == 'undefined') {
        step = 1;
    }

    if ((step > 0 && start >= stop) || (step < 0 && start <= stop)) {
        return [];
    }

    var result = [];
    for (let i = start; step > 0 ? i < stop : i > stop; i += step) {
        result.push(i);
    }

    return result;
};

var map = L.map('map');
var lines = L.layerGroup();
var legend = L.control({position: 'topright'});

var date_range = document.getElementById('date_range');
noUiSlider.create(date_range, {
    start: [
        Date.now()/1000 - 24*60*60*150,
        Date.now()/1000 + 60*60*24*14
    ],
    connect: true,
    range: {
        min: 1535342400,
        max: Date.now()/1000 + 60*60*24*14
    },
    pips: {
        mode: 'values',
        values: range(0, 12 * ((new Date()).getFullYear() - 2018)
                            + ((new Date()).getMonth()) - 8 + 1, 3)
            .map((months) => {
                let d = new Date('2018-09-01');
                d.setFullYear(2018 + (months + 8)/12);
                d.setMonth((months + 8) % 12);
                return d.valueOf()/1000;
            }),
        format: {to: (v) => (new Date(v*1000)).toLocaleString('en-US', {year:'numeric', month: 'short'})}
    }
});

legend.onAdd = (map) => {
    let div = L.DomUtil.create('div', 'legend');
    //div.appendChild(date_range);
    div.innerHTML += "<div><div class='line red dash'></div>Milling later this week</div>";
    div.innerHTML += "<div><div class='line red'></div>Milled street</div>";
    div.innerHTML += "<div><div class='line purple dash'></div>Paving later this week</div>";
    div.innerHTML += "<div><div class='line blue'></div>Recently paved street</div>";
    return div;
};
legend.addTo(map);

// add an OpenStreetMap tile layer
L.tileLayer('http://{s}.tile.osm.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="http://osm.org/copyright">OpenStreetMap</a> contributors',
    className: 'tiles'
}).addTo(map);

/*var url = 'actions.json';
if (window.location.hostname == 'localhost') {
    url = 'index.php?actions';
}*/


function redraw(segments) {
    //draw each street segment with the most recent action
    lines.clearLayers();

    for (let v of segments.values()) {
        let line = points_to_polyline(v.points);
        v.actions.sort((a, b) => b.action_date - a.action_date);

        //don't show segments with no actions inside the date_range
        if (   v.actions[0].action_date  //latest action is before the beginning of the range
                <= date_range.noUiSlider.get()[0]
            || v.actions[v.actions.length-1].action_date
                >= date_range.noUiSlider.get()[1]) {//first action is after the end of the range
            continue;
        }

        let action = v.actions[0];

        if (action.is_milling && action.action_date >= Date.now()/1000) {
            line.setStyle({weight: 2, color: '#f00', dashArray: '1 10'});
        } else if (action.is_milling) {
            line.setStyle({weight: 2, color: '#f00'});
        } else if (action.action_date >= Date.now()/1000) {
            line.setStyle({weight: 2, color: '#a0f', dashArray: '1 10'});
        } else {
            line.setStyle({weight: 2, color: '#00f'});
        }

        line.bindPopup(popup(v.actions));
        line.addTo(lines);
    }
    lines.addTo(map);
}

function show_errors(errors) {
    document.getElementById('errors').innerHTML = 'Errors this run: <label for=show_errors></label><br><input id=show_errors type=checkbox>';
    document.getElementById('errors').appendChild(
        buildHtmlTable(
            errors.filter(
                (e) => e.action_date > date_range.noUiSlider.get()[0]
                    && e.action_date < date_range.noUiSlider.get()[1]),
            {
                'action_date': d => document.createTextNode(format_date(d))
            })
        );
}

var actions = [];

//TODO: don't refetch everything every time the slider is changed

async function update() {
    let segments = new Map();
    let errors = [];
    let fetches = await Promise.allSettled(
        range(parseInt(date_range.noUiSlider.get()[0]), 
              parseInt(date_range.noUiSlider.get()[1]),
              24*60*60).map((s) => new Date(s * 1000)).map(
            (day) => fetch('actions/' + day.getFullYear() 
                                + '/' + (day.getMonth() + 1) 
                                + '/' + day.getDate() 
                                + '.json').then((r) => 
                !r.ok ? null : r.json().then((response_actions) => {
                    for (let action of response_actions) {
                        if ('error_code' in action) {
                            errors.push(a);
                        } else if ('points' in action) { //legacy: {points: {error_code: xx}}
                            if ('error_code' in action.points) {
                                let a = action;
                                a.error_code = a.points.error_code;
                                a.error_message = a.points.error_message;
                                a.points = [];
                                delete a.points;
                                errors.push(a);
                            } else {
                                for (let i = 0; i < action.points.length - 1; i++) {
                                    if ('gap' in action.points[i+1]) continue;
                                    let p1 = null, p2 = null;
                                    if (action.points[i] < action.points[i+1]) {
                                        p1 = action.points[i];
                                        p2 = action.points[i+1];
                                    } else {
                                        p1 = action.points[i+1];
                                        p2 = action.points[i];
                                    }
                                    let key = p1.x + p1.y + p2.x + p2.y;
                                    
                                    if (!segments.has(key)) {
                                        segments.set(key, {points: [p1, p2], actions: []})
                                    }
                                    let a2 = action;
                                    delete a2.segments;
                                    segments.get(key).actions.push(a2);
                                }
                            }
                        } else if ('segments' in action) { //new: {segments: []}
                            for (segment of action.segments) {
                                let key = segment.points[0].x 
                                        + segment.points[0].y 
                                        + segment.points[1].x 
                                        + segment.points[1].y;
                                if (!segments.has(key)) {
                                    segments.set(key, {points: segment.points, actions: []})
                                }
                                let a2 = action;
                                delete a2.segments;
                                segments.get(key).actions.push(a2);
                            }
                        }
                    }
                }).catch(function(err) { setTimeout(function() { throw err; }); })
            )
        )
    );
    await Promise.all(
        fetches.map((p) =>
            p.status == 'fulfilled' ? p.value : null
        )
    );
    
    redraw(segments);
    show_errors(errors);
}


map.on('zoomend', function() {
    lines.eachLayer(function(layer) {
        if (layer.options.dashArray) {
            layer.setStyle({dashArray: Math.pow(2, map.getZoom()) / 1.5e6 * 200});
            layer.setStyle({dashOffset: Math.pow(2, map.getZoom()) / 1.5e6 * 100});
        }
        if (map.getZoom() > 15) {
            layer.setStyle({weight: Math.pow(2, map.getZoom()) / 1.5e6 * 50});
        } else if (map.getZoom() > 12) {
            layer.setStyle({weight: 3});
        } else {
            layer.setStyle({weight: 2});
        }
    });
});
map.setView([40.7358,-73.9243], 10);

date_range.noUiSlider.on('set', () => update());
update();
