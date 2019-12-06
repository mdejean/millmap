<?php
ini_set('html_errors', 0);

$db = new SQLite3('millmap.sqlite');

function get_last_date($db, $url) {
    $q = $db->prepare('select max(access_date) last_date from schedules where url = :url');
    $q->bindValue(':url', $url);
    $result = $q->execute();
    $ret = $result->fetchArray()[0];
    $result->finalize();
    return $ret;
}

function pdf_to_text($pdf) {
    $file = tmpfile();
    $output = tempnam(sys_get_temp_dir(), "MIL");
    fwrite($file, $pdf);
    fseek($file, 0);
    $p = exec("mutool convert -F text -o " . escapeshellarg($output) . " " . escapeshellarg(stream_get_meta_data($file)['uri']));
    fclose($file);
    $text = file_get_contents($output);
    unlink($output);
    return $text;
}

function parse_schedule($db, $s, $start_date) {
    $ret = 0; //returns number of rows added

    $boroughs = ['Manhattan' => 1, 'Bronx' => 2, 'Brooklyn' => 3, 'Queens' => 4, 'Staten Island' => 5];
    $weekdays = array_flip(['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']);
    
    $borough = 0;
    $action_date = null;
    $is_milling = null;
    
    //start by searching the document for the borough, because somehow it's not ending up at the top
    foreach ($boroughs as $k => $v) {
        if (preg_match('/\n\s*' . $k . '\s*\n/', $s)) {
            $borough = $v;
        }
    }
    
    foreach (explode("\n", $s) as $line) {
        $line = trim($line, " \t\r\n\xef\xbb\xbf\0");

        if (isset($boroughs[$line])) {
            $borough = $boroughs[$line];
        } elseif (isset($weekdays[$line])) {
            $action_date = $start_date + $weekdays[$line] * (60 * 60 * 24);
            $is_milling = null;
        } elseif (stripos($line, 'paving') !== false) {
            $is_milling = false;
        } elseif (stripos($line, 'milling') !== false) {
            $is_milling = true;
        } elseif (preg_match('/^(\d+-\d+-\d+)? ?([MQKXS][\d-]+)? ?([^(-]*)(\(| ?- ?)([^)-]*)( to | ?- ?)([^)]*)\)? ?(- \w+)? ?(\d+)? ?(.*)?$/', $line, $match)) {
            //example: 7-26-18 M2018-08-17 Broadway (218th St to W 225th St Bridge) 12 Inwood
            list(            , $date,            $sa,            $on_street, , $from_street, , $to_street, , $cb, $neighborhood) = $match;
            if (empty($on_street) or preg_match('/^([MQKXS]?[\d-]+)$/', $on_street)) continue;
            $q = $db->prepare('insert into actions values (:action_date, :is_milling, :borough, :on_street, :from_street, :to_street, :sa, :cb, :neighborhood)');
            
            $q->bindValue(':action_date', $action_date);
            $q->bindValue(':is_milling', $is_milling);
            $q->bindValue(':borough', $borough);
            $q->bindValue(':on_street', trim($on_street)); //TODO: fix regex to not include whitespace
            $q->bindValue(':from_street', trim($from_street));
            $q->bindValue(':to_street', trim($to_street));
            $q->bindValue(':sa', $sa);
            $q->bindValue(':cb', $cb);
            $q->bindValue(':neighborhood', $neighborhood);
            
            if ($q->execute() === false) {
                echo $action_date, "'" . $line . "'" . "\n";
            } else {
                $ret++;
            }
        }
    }
    
    return $ret;
}

function schedule_date($text) {
    $start_date = null;
    $end_date = null;
    preg_match('/Milling & Paving Schedule\s?\n+(.*)/', $text, $match);
    if (isset($match[1])) {
        $dates = explode(' to ', $match[1]);
        if (!isset($dates[1])) {
            $dates = explode(' through ', $match[1]);
        }
        date_default_timezone_set('America/New_York');
        $start_date = strtotime($dates[0]);
        $end_date = strtotime($dates[1]);
    }
    
    if (empty($start_date)) {
        preg_match('/Sunday\s*\n+([^\s]+)\n+No Work/', $text, $match);
        $start_date = strtotime($match[1]);
    }
    
    return [$start_date, $end_date];
}

function fetch_updates($db) {
    $urls = [
        'http://www.nyc.gov/html/dot/downloads/pdf/artresurf.pdf',
        'http://www.nyc.gov/html/dot/downloads/pdf/mnresurf.pdf',
        'http://www.nyc.gov/html/dot/downloads/pdf/bkresurf.pdf',
        'http://www.nyc.gov/html/dot/downloads/pdf/qnresurf.pdf',
        'http://www.nyc.gov/html/dot/downloads/pdf/siresurf.pdf',
        'http://www.nyc.gov/html/dot/downloads/pdf/bxresurf.pdf',
    ];
    foreach ($urls as $url) {
        $last_update = get_last_date($db, $url);
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_TIMECONDITION, CURL_TIMECOND_IFMODSINCE);
        curl_setopt($curl, CURLOPT_TIMEVALUE, $last_update);
        curl_setopt($curl, CURLOPT_FILETIME, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($curl);
        if ($result === false 
            or curl_getinfo($curl, CURLINFO_RESPONSE_CODE) != '200' 
            or curl_getinfo($curl, CURLINFO_FILETIME) <= $last_update) {
            curl_close($curl);
        } else {
            curl_close($curl);
            $text = pdf_to_text($result);
            
            list($start_date, $end_date) = schedule_date($text);

            $q = $db->prepare('insert into schedules values (:url, :access_date, :file, :text, :start_date, :end_date, 0)');
            $q->bindValue(':url', $url); 
            $q->bindValue(':access_date', time()); 
            $q->bindParam(':file', $result, SQLITE3_BLOB); 
            $q->bindValue(':text', $text); 
            $q->bindValue(':start_date', $start_date); 
            $q->bindValue(':end_date', $end_date);
            $q->execute();
            echo "got $url\n";
        }
    }
}

function reconvert_schedules($db) {
    $schedules = $db->query('select url, access_date, file from schedules');
    $q = $db->prepare('update schedules set converted_text = :text, start_date = :start, end_date = :end where url = :url and access_date = :access_date');
    while (($row = $schedules->fetchArray(SQLITE3_ASSOC)) !== false) {
        $text = pdf_to_text($row['file']);
        
        list($start_date, $end_date) = schedule_date($text);
        
        $q->bindValue(':text', $text);
        $q->bindValue(':url', $row['url']);
        $q->bindValue(':start', $start_date);
        $q->bindValue(':end', $end_date);
        $q->bindValue(':access_date', $row['access_date']);
        $q->execute();
        $q->reset();
    }
}

function parse_schedules($db, $all = false) {
    $ret = 0;
    if ($all) {
        $db->query('delete from actions');
    }
    $result = $db->query('select url, access_date, start_date, converted_text from schedules' . ($all ? '' : ' where processed = 0'));
    $q = $db->prepare('update schedules set processed = 1 where url = :url and access_date = :access_date');
    while (($row = $result->fetchArray()) !== false) {
        $ret += parse_schedule($db, $row['converted_text'], $row['start_date']);
        $q->bindValue(':url', $row['url']);
        $q->bindValue(':access_date', $row['access_date']);
        $q->execute();
        $q->reset();
    }
    return $ret;
}

function add_street_stretches($db) {
    $valid = 0;
    $invalid = 0;

    $street_stretches = $db->query("
select 
    coalesce(c.new_borough,     a.borough    ) borough       ,
    coalesce(c.new_on_street,   a.on_street  ) on_street     ,
    coalesce(c.new_from_street, a.from_street) from_street   ,
    coalesce(c.new_to_street,   a.to_street  ) to_street     ,
    coalesce(c.from_direction, '')             from_direction,
    coalesce(c.to_direction, '')               to_direction  
from actions a 
left join corrections c
    on  (a.borough     = c.borough     or c.borough is null)
    and (a.on_street   = c.on_street   or c.on_street is null)
    and (a.from_street = c.from_street or c.from_street is null)
    and (a.to_street   = c.to_street   or c.to_street is null)
left join street_stretches ss
    on  coalesce(c.new_borough,     a.borough    ) = ss.borough
    and coalesce(c.new_on_street,   a.on_street  ) = ss.on_street
    and coalesce(c.new_from_street, a.from_street) = ss.from_street
    and coalesce(c.new_to_street,   a.to_street  ) = ss.to_street
    and coalesce(c.from_direction, '') = ss.from_direction
    and coalesce(c.to_direction, '') = ss.to_direction
where ss.points is null");

    $add_ss = $db->prepare('
insert into street_stretches (borough, on_street, from_street, to_street, from_direction, to_direction, points, needs_trimming) values (:borough, :on_street, :from_street, :to_street, :from_direction, :to_direction, :points, :needs_trimming)');
    while (($row = $street_stretches->fetchArray()) !== false) {
        $cmd = './streetstretch ' . escapeshellarg($row['borough'])
            . ' ' . escapeshellarg($row['on_street']) 
            . ' ' . escapeshellarg($row['from_street']) 
            . ' ' . escapeshellarg($row['to_street'])
            . ' ' . escapeshellarg($row['from_direction']) 
            . ' ' . escapeshellarg($row['to_direction']);
        $out = exec($cmd);
        
        // 66: streets intersect more than twice, cannot be processed
        // add these as full length of street for manual processing
        $needs_trimming = false;
        
        if (strpos($out, '{"error_code": "66"') === 0) {
            $cmd = './streetstretch ' . escapeshellarg($row['borough']) . ' ' . escapeshellarg($row['on_street']);
            $out = exec($cmd);
            $needs_trimming = true;
        }

        $add_ss->bindValue(':borough', $row['borough']);
        $add_ss->bindValue(':on_street', $row['on_street']);
        $add_ss->bindValue(':from_street', $row['from_street']);
        $add_ss->bindValue(':to_street', $row['to_street']);
        $add_ss->bindValue(':from_direction', $row['from_direction']);
        $add_ss->bindValue(':to_direction', $row['to_direction']);
        $add_ss->bindValue(':points', $out);
        $add_ss->bindValue(':needs_trimming', $needs_trimming);
        $add_ss->execute();
        $add_ss->reset();
        if (strpos($out, "{") === 0) {
            $invalid++;
        } else {
            $valid++;
        }
    }
    
    echo "added $valid valid and $invalid invalid street stretches\n";
}

function generate_json($db) {
    $result = $db->query("
select
    a.is_milling,
    a.action_date,
    ss.points,
    a.borough,
    a.on_street,
    a.from_street,
    a.to_street
from actions a
left join corrections c
    on  (a.borough     = c.borough     or c.borough is null)
    and (a.on_street   = c.on_street   or c.on_street is null)
    and (a.from_street = c.from_street or c.from_street is null)
    and (a.to_street   = c.to_street   or c.to_street is null)
left join street_stretches ss
    on  coalesce(c.new_borough,     a.borough    ) = ss.borough
    and coalesce(c.new_on_street,   a.on_street  ) = ss.on_street
    and coalesce(c.new_from_street, a.from_street) = ss.from_street
    and coalesce(c.new_to_street,   a.to_street  ) = ss.to_street
    and coalesce(c.from_direction, '') = ss.from_direction
    and coalesce(c.to_direction, '') = ss.to_direction
order by a.is_milling desc, a.action_date");
    echo '[';
    $once = true;
    while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
        if ($once) {
            $once = false;
        } else {
            echo ",\n";
        }
        $row['points'] = json_decode($row['points']);
        echo json_encode($row);
    }
    echo ']';
}

function list_corrections($db) {
    $result = $db->query("
select
    ss.borough,
    ss.on_street,
    ss.from_street,
    ss.to_street,
    c.rowid,
    c.new_borough,
    c.new_on_street,
    c.new_from_street,
    c.new_to_street,
    c.from_direction,
    c.to_direction,
    case when ss2.points like '{%' then ss2.points else null end as error
from (select distinct
    borough,
    on_street,
    from_street,
    to_street
    from actions a) ss
left join corrections c
    on  (ss.borough     = c.borough     or c.borough is null)
    and (ss.on_street   = c.on_street   or c.on_street is null)
    and (ss.from_street = c.from_street or c.from_street is null)
    and (ss.to_street   = c.to_street   or c.to_street is null)
left join street_stretches ss2
    on  coalesce(c.new_borough,     ss.borough    ) = ss2.borough
    and coalesce(c.new_on_street,   ss.on_street  ) = ss2.on_street
    and coalesce(c.new_from_street, ss.from_street) = ss2.from_street
    and coalesce(c.new_to_street,   ss.to_street  ) = ss2.to_street
    and coalesce(c.from_direction, '') = ss2.from_direction
    and coalesce(c.to_direction, '') = ss2.to_direction
");
    echo '[';
    $once = true;
    while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
        if ($once) {
            $once = false;
        } else {
            echo ",\n";
        }
        $e = json_decode($row['error']);
        unset($row['error']);
        if (!empty($e)) {
            $row['error_code'] = $e->error_code;
            $row['error_message'] = $e->error_message;
        }
        echo json_encode($row);
    }
    echo ']';
}

function cmd($k) {
    global $argv;
    return isset($_GET[$k]) or (isset($argv[1]) and $argv[1] == $k);
}

if (cmd('update')) {
    header('Content-Type: text/plain');
    fetch_updates($db);
    $new_actions = parse_schedules($db, false);
    echo "added $new_actions actions\n";
    add_street_stretches($db);
}

if (cmd('reconvert')) {
    reconvert_schedules($db);
}

if (cmd('reparse')) {
    $new_actions = parse_schedules($db, true);
    echo "added $new_actions actions\n";
    //$db->exec("delete from street_stretches");
    add_street_stretches($db);
}

if (cmd('actions')) {
    header('Content-Type: application/json'); 
    generate_json($db);
}

if (cmd('corrections')) {
    header('Content-Type: application/json');
    list_corrections($db);
}

if (cmd('schedules')) {
    header('Content-Type: application/json');
    $result = $db->query("
select
    url,
    access_date,
    start_date,
    end_date,
    length(file) as filesize
from schedules order by access_date, url");
    echo '[';
    $once = true;
    while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
        if ($once) {
            $once = false;
        } else {
            echo ",\n";
        }
        echo json_encode($row);
    }
    echo ']';
}

if (cmd('download')) {
    $query = $db->prepare("select file from schedules where access_date = :access_date and url = :url");
    $query->bindValue(":url", $_GET['url']);
    $query->bindValue(":access_date", $_GET['access_date']);
    if (!($result = $query->execute())) {
        http_response_code(500);
    } else {
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row === false) {
            http_response_code(404);
        } else {
            header('Content-Type: application/pdf');
            echo $row['file'];
        }
    }
}

if (cmd('to_trim')) {
    header('Content-Type: application/json');
    $result = $db->query("
select
    ss.borough,
    ss.on_street,
    ss.from_street,
    ss.to_street,
    ss.from_direction,
    ss.to_direction,
    ss.needs_trimming,
    ss.points
from street_stretches ss
where ss.needs_trimming = 1");
    echo '[';
    $once = true;
    while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
        if ($once) {
            $once = false;
        } else {
            echo ",\n";
        }
        $row['points'] = json_decode($row['points']);
        echo json_encode($row);
    }
    echo ']';
}
if (isset($_GET['trim'])) {
    $columns = [
        'borough',
        'on_street',
        'from_street', 
        'to_street',
        'from_direction',
        'to_direction'];
    $q = $db->prepare("update street_stretches set points = :points, needs_trimming = 0 where " 
        . implode(" and ", 
            array_map(function($v) {return $v . ' = :' . $v;}, $columns)
          )
        );
    
    $q->bindValue(":points", $_POST['points']);
    foreach ($columns as $column) {
        $q->bindValue(":$column", $_POST[$column]);
    }
    
    if (!$q->execute()) {
        http_response_code(500);
    }
}
if (isset($_GET['add_correction'])) {
    $columns = [
        'borough',
        'on_street',
        'from_street', 
        'to_street',
        'new_borough',
        'new_on_street',
        'new_from_street', 
        'new_to_street',
        'from_direction',
        'to_direction'];
    
    $q = null;
    if (!empty($_POST['rowid'])) {
        $q = $db->prepare('update corrections set ' 
            . implode(',', array_map(function($v) {return $v . ' = :' . $v;}, $columns))
            . ' where rowid = :rowid');
        $q->bindValue(':rowid', $_POST['rowid']);
    } else {
        $q = $db->prepare(
            'insert into corrections (' 
            . implode(',', $columns) 
            . ') values (' 
            . implode(',', array_map(function($v) {return ':' . $v;}, $columns)) 
            . ')');
    }
    
    foreach ($columns as $column) {
        if (isset($_POST[$column])) {
            $q->bindValue(':' . $column, $_POST[$column]);
        } else {
            $q->bindValue(':' . $column, '');
        }
    }

    if (!$q->execute()) {
        http_response_code(500);
    }
    
    add_street_stretches($db);
}

if (cmd('update_schema')) {
    header('Content-Type: text/plain');
    $success = true;
    $tables = ['schedules', 'actions', 'street_stretches', 'corrections'];
    foreach ($tables as $t) {
        $success = $db->exec("alter table $t rename to old_$t;");
        if (!$success) {
            break;
        }
    }
    if (!$success) {
        die("wtf");
    }
    $db->exec('
create table schedules (
    url text, 
    access_date int, 
    file blob, 
    converted_text text, 
    start_date int, 
    end_date int, 
    processed int not null default 0,
    primary key (url, access_date)
) without rowid');
    $db->exec('
create table actions (
    action_date int, 
    is_milling int, 
    borough int,
    on_street text,
    from_street text, 
    to_street text,
    sa text,
    community_board int,
    neighborhood text,
    primary key (action_date, is_milling, borough, on_street, from_street, to_street)
) without rowid');
    $db->exec('
create table street_stretches (
    borough int,
    on_street text,
    from_street text, 
    to_street text,
    from_direction text not null default \'\',
    to_direction text not null default \'\',
    points text,
    needs_trimming int,
    primary key (borough, on_street, from_street, to_street, from_direction, to_direction)
) without rowid');
    $db->exec('
create table corrections (
    borough int,
    on_street text,
    from_street text, 
    to_street text,
    new_borough int,
    new_on_street text,
    new_from_street text, 
    new_to_street text,
    from_direction text,
    to_direction text)');
    foreach ($tables as $t) {
        $columns = array_keys($db->querySingle("select * from old_$t limit 1", true));
        $success = $db->exec("insert into $t (" . implode(",", $columns) . ") select * from old_$t");
        if (!$success) break;
    }
    if ($success) {
        foreach ($tables as $t) {
            $db->exec("drop table old_$t");
        }
        echo "schema update succeeded";
    } else {
        foreach ($tables as $t) {
            $db->exec("drop table $t; alter table old_$t rename to $t;");
        }
        echo "schema update failed";
    }
}

$db->close();
