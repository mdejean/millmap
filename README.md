# NYC street milling & paving map generator

<img src='https://mdejean.github.io/millmap.png'>

[View the map](https://mdejean.github.io/millmap/map.html)

This is based on the milling and paving [schedules](http://www.nyc.gov/html/dot/html/motorist/resurfintro.shtml) put out by NYCDOT. The
schedules are not comprehensive and there are sometimes actions missing 
entirely. Not everything goes to schedule, too.

## Installation

Requirements:

* PHP 7+
* [Geosupport Desktop Edition](https://www1.nyc.gov/site/planning/data-maps/open-data/dwn-gde-home.page)
* A C compiler

Steps:

* Compile `streetstretch`, e.g., `gcc streetstretch.c NYCgeo.lib -o streetstretch`
* Run `php -S localhost:8080`
* Set up the database `localhost:8080/?update_schema`
* Download the latest schedules `localhost:8080/?update`
* Correct the many errors `localhost:8080/corrections.html`. [GOAT](http://a030-goat.nyc.gov/goat/f3s.aspx) will help.
* View the map `localhost:8080/map.html`

TODO:

* Hide paved streets after a few months
* Integrate [street closures feed](https://data.cityofnewyork.us/Transportation/Street-Closures-due-to-construction-activities-by-/i6b5-j7bu), which may have better data 
* Use weather history to predict whether paving actually happened
* Crowdsource corrections?