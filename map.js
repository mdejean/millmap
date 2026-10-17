"use strict";

proj4.defs('FIPS:3104','+proj=lcc +lat_1=40.66666666666666 +lat_2=41.03333333333333 +lat_0=40.16666666666666 +lon_0=-74 +x_0=300000 +y_0=0 +ellps=GRS80 +datum=NAD83 +to_meter=0.3048006096012192 +no_defs ');
const from_latlng = proj4('FIPS:3104', 'WGS84');
function point_to_latlng(p) {
    let n = from_latlng.forward(p);
    if (Number.isNaN(n.x)) throw "wtf " + p.x;
    return L.latLng(n.y, n.x);
}

function points_to_polyline(points) {
    let lls = [];
    for (let point of points) {
        lls.push(point_to_latlng(point));
    }
    return L.polyline(lls);;
}
// LINESTRING (981534.31340000033 159279.26299999654, 981264.612499997 158935.16290000081)
function wkt_to_points(wkt) {
    if (!wkt.startsWith('LINESTRING')) return [];
    const regex = /(-?\d+(\.\d+)?\s+-?\d+(\.\d+)?)/g;
    const matches = wkt.match(regex);
    return matches.map(match => {
        const [x, y] = match.split(/\s+/).map(Number);
        return {x: x, y: y};
    });
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
        s += "</td></tr>";
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

const days = 24 * 60 * 60;
const initial_min_date = Date.now()/1000 - ((Date.now()/1000 - 3 * days) % (7 * days)) - (91 * days); // 91 days before last Sunday
const initial_max_date = Date.now()/1000 - ((Date.now()/1000 - 3 * days) % (7 * days)) + (14 * days); // 14 days after last Sunday

var date_range = document.getElementById('date_range');
noUiSlider.create(date_range, {
    start: [
        initial_min_date,
        initial_max_date
    ],
    connect: true,
    range: {
        min: 1535342400,
        max: Date.now()/1000 + 60*60*24*14
    },
    pips: {
        mode: 'values',
        values: range(0, 12 * ((new Date()).getFullYear() - 2018)
                            + ((new Date()).getMonth()) - 8 + 1, 6)
            .map((months) => {
                let d = new Date('2019-11-01');
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

var actions = [];

//TODO: don't refetch everything every time the slider is changed

async function update() {
    let segments = new Map();
    const start_date = new Date(parseInt(date_range.noUiSlider.get()[0]) * 1000).toISOString().slice(0,-1);
    const end_date = new Date(parseInt(date_range.noUiSlider.get()[1]) * 1000).toISOString().slice(0,-1);
    
    let response = await fetch(
        "https://data.cityofnewyork.us/resource/xnfm-u3k5.json?"
            + "$select=date,worktype,location_wkt"
            + "&$where=tostreetname is not null and "
            + "date between '"+start_date+"' and '"+end_date+"'"
            + "&$limit=100000");
    if (response.status != 200) return;
    
    let actions = await response.json();
    let max_date = 0;
    for (let action of actions) {
        if (!segments.has(action.location_wkt)) {
            segments.set(action.location_wkt, {points: wkt_to_points(action.location_wkt), actions: []});
        }
        let d = Date.parse(action.date + 'Z') / 1000;
        segments.get(action.location_wkt).actions.push(
            {
                is_milling: action.worktype == 'MILLING',
                action_date: d,
            }
        );
        if (d > max_date) {
            max_date = d;
        }
    }
    document.getElementById('header').innerHTML = "Milling &amp; Paving through " + format_date(max_date);
    redraw(segments);
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
