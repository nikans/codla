<?php
/**
 * Created by PhpStorm.
 * User: nikans
 * Date: 27/11/14
 * Time: 10:14 PM
 */

require_once('php/SqlFormatter.php');

$requests = array();
$request_data = array();
$requests_log = array();
$isTransaction = 0;

if (isset($_POST['analyze'])) {
    $log = trim($_POST['log']);
    $logArray = explode("\n", $log);
    unset($log);
    $logArray = array_filter($logArray, 'trim');

    foreach ($logArray as $line) {

        if(preg_match("/^.*sql: (.*)$/", $line, $matches)) {

            if(!empty($request_data)) {
                $request_data['isTransaction'] = $isTransaction;
                $requests[addslashes($request_data['request'])][] = $request_data;
                $requests_log[] = $request_data;
            }

            $request_data = array();

            $request_data['request'] = $matches[1];
            $request_data['request'] = trim($request_data['request'] );
            $request_data['request'] = preg_replace("/LIMIT \d+/", "LIMIT #", $request_data['request']);
            $request_data['request'] = preg_replace("/OFFSET \d+/", "OFFSET #", $request_data['request']);

            if(preg_match("/^.*sql: COMMIT.*$/", $line, $matches)) {
                $isTransaction = 0;
            }
        }

        if(preg_match("/^.*sql: BEGIN.*$/", $line, $matches)) {
            $isTransaction = 1;
        }

        if(preg_match("/^.*annotation: sql connection fetch time: (.*)s.*$/", $line, $matches)) {
            $request_data['connectionTime'] = $matches[1];
        }

        if(preg_match("/^.*annotation: total fetch execution time: (.*)s for (.) rows.*$/", $line, $matches)) {
            $request_data['fetchTime'] = $matches[1];
            $request_data['fetchRows'] = $matches[2];
        }

        if(preg_match("/^.*annotation: sql execution time: (.*)s.*$/", $line, $matches)) {
            $request_data['fetchTime'] = $matches[1];
        }
    }

    unset($request_data);
    unset($logArray);

    $requests_to_sort = array();

    foreach ($requests as $request => $request_samples) {
        $new_request = array();
        $new_request['request'] = stripslashes($request);
        $new_request['count'] = 0;
        $new_request['total_fetch_time'] = 0;
        $new_request['total_time'] = 0;
        $new_request['max_time'] = 0;
        $new_request['min_time'] = INF;

        foreach ($request_samples as $request_data) {
            $new_request['count']++;
            if(isset($request_data['fetchTime'])) {
                $new_request['total_fetch_time'] += $request_data['fetchTime'];
                $new_request['total_time'] += $request_data['fetchTime'];
                $request_time = $request_data['fetchTime'];

                if(isset($request_data['connectionTime'])) {
                    $new_request['total_time'] += $request_data['connectionTime'];
                    $request_time += $request_data['connectionTime'];
                }

                $new_request['max_time'] = $new_request['max_time'] < $request_time ? $request_time : $new_request['max_time'];
                $new_request['min_time'] = $new_request['min_time'] > $request_time ? $request_time : $new_request['min_time'];
            }
        }

        $new_request['min_time'] = $new_request['min_time'] == INF ? 0 : $new_request['min_time'];
        $new_request['total_fetch_time'] = round($new_request['total_fetch_time'], 3);
        $new_request['total_time']       = round($new_request['total_time'], 3);
        $new_request['max_time']         = round($new_request['max_time'], 3);
        $new_request['min_time']         = round($new_request['min_time'], 3);

        if($new_request['total_time'] != 0)
            $requests_to_sort[] = $new_request;
    }

    unset($requests);


    $sort_by_impact = function($a, $b) {
        return $a["total_time"] < $b["total_time"];
    };
    usort($requests_to_sort, $sort_by_impact);
}

?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Core Data Queries Analyzer</title>

    <!-- Bootstrap -->
    <link href="css/bootstrap.min.css" rel="stylesheet">

    <!-- Highlight -->
    <link href="css/tomorrow.css" rel="stylesheet">

    <!-- HTML5 shim and Respond.js for IE8 support of HTML5 elements and media queries -->
    <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
    <!--[if lt IE 9]>
    <script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
    <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->

    <style>
        .nobr      { white-space:nowrap; }
        .monospace { font-family: "Monaco", "Inconsolata", "Consolas", monospace; word-wrap:break-word; word-break:break-all; }
        .sql       { background-color:transparent !important; white-space:pre-wrap; }
        #modeTabs  { border-bottom:none; margin-bottom:1px; }
        .navbar-brand img { height:40px; position:relative; top:-10px; }
        body       { padding-top:60px; }
    </style>

</head>
<body>

<nav class="navbar navbar-default navbar-fixed-top" role="navigation">
    <div class="container">
        <div class="navbar-header">
            <a class="navbar-brand" href="http://cocoaheads.ru/">
                <img alt="CocoaHeads MSK" src="img/logo-cocoaheads.png">
            </a>
        </div>
        <p class="navbar-text navbar-right">
            <a href="http://cocoaheads.ru/" class="navbar-link">CocoaHeads Moscow</a> &middot;
            <a href="https://github.com/CocoaHeadsMsk/coredata-query-analyser" class="navbar-link">GitHub</a>
        </p>
    </div>
</nav>

<div class="container">
    <div class="row">
        <div class="col-xs-12">
            <h1>Core Data Queries Analyzer</h1>

            <div class="panel panel-default" style="margin-top:30px;">
                <div class="panel-heading">
                    <h3 class="panel-title">What is it and how to use this</h3>
                </div>
                <div class="panel-body">
                    <p>This scripts analyses CoreData debug log, aggregates similar queries, produces statistics and formats messed-up queries into readable ones.</p>
                    <ol>
                        <li>Add <code>-com.apple.CoreData.SQLDebug 1</code> to your target schema's "Arguments Passed On Launch"</li>
                        <li>Copy run log and paste it here. This is an early beta so the script may choke if the log is too huge :)</li>
                    </ol>
                </div>
            </div>

            <form action="#" method="post" role="form">
                <div class="form-group">
                    <label for="log">Copy your log here</label>
                    <textarea name="log" id="log" class="form-control monospace" rows="<?php echo empty($requests_to_sort) ? '10' : '3'; ?>"></textarea>
                </div>
                <input type="submit" name="analyze" value="Analyze" class="btn btn-primary" />
            </form>
        </div>
    </div>
    <?php

    if(!empty($requests_to_sort)) {
        ?>

        <div class="row" style="margin-top:50px;">
            <div class="col-xs-12">

                <div class="panel panel-default">
                    <div class="panel-body">
                        Analytics tab displays only queries with logged execution time. You can trace all queries in Log tab.
                    </div>
                </div>

                <div role="tabpanel">

                    <!-- Nav tabs -->
                    <ul class="nav nav-tabs" role="tablist" id="modeTabs">
                        <li role="presentation" class="active"><a href="#analytics" role="tab" data-toggle="tab">Analytics</a></li>
                        <li role="presentation"><a href="#queries" role="tab" data-toggle="tab">Log</a></li>
                    </ul>

                    <!-- Tab panes -->
                    <div class="tab-content">
                        <div role="tabpanel" class="tab-pane active" id="analytics">

                            <table class="table table-bordered">
                                <tr>
                                    <th>Total time</th>
                                    <th>Count</th>
                                    <th>Avg time</th>
                                    <th>Min time</th>
                                    <th>Max time</th>
                                    <th>Request</th>
                                </tr>
                                <?php

                                foreach($requests_to_sort as $request) {
                                    $avg_time = round(($request['total_time']/$request['count']), 3);

                                    ?><tr class="monospace <?php echo ($avg_time > 0.5 ? "danger" : ($avg_time > 0.2 ? "warning" : "" )); ?>">
                                    <td class="nobr"><?=$request['total_time'];?></td>
                                    <td class="nobr"><?=$request['count'];?></td>
                                    <td class="nobr"><?=$avg_time;?></td>
                                    <td class="nobr"><?=$request['min_time'];?></td>
                                    <td class="nobr"><?=$request['max_time'];?></td>
                                    <td>
                                        <div class="sql-request sql"><?=SqlFormatter::format($request['request'], false);?></div>
                                    </td>
                                    </tr><?php
                                }

                                ?>
                            </table>
                        </div>

                        <div role="tabpanel" class="tab-pane" id="queries">

                            <table class="table table-bordered">
                                <tr>
                                    <th>#</th>
                                    <th>Total time</th>
                                    <th>Connection time</th>
                                    <th>Fetch time</th>
                                    <th>Fetched rows</th>
                                    <th>Request</th>
                                    <th>Transaction</th>
                                </tr>
                                <?php

                                foreach($requests_log as $id => $request) {
                                    ?><tr class="monospace <?php echo (@$request['isTransaction'] ? "warning" : ""); ?>">
                                    <td class="nobr"><?=$id;?></td>
                                    <td class="nobr"><?=@$request['fetchTime']+@$request['connectionTime'];?></td>
                                    <td class="nobr"><?=@$request['fetchTime'];?></td>
                                    <td class="nobr"><?=@$request['connectionTime'];?></td>
                                    <td class="nobr"><?=@$request['fetchRows'];?></td>
                                    <td>
                                        <div class="sql-request sql"><?=SqlFormatter::format($request['request'], false);?></div>
                                    </td>
                                    <td class="nobr"><?php echo @$request['isTransaction'] ? '<span class="glyphicon glyphicon-ok"></span>' : '';?></td>
                                    </tr><?php
                                }

                                ?>
                            </table>

                        </div>

                    </div>
                </div>
            </div>
        </div>

    <?php
    }
    ?>
</div>

<!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.1/jquery.min.js"></script>
<!-- Include all compiled plugins (below), or include individual files as needed -->
<script src="js/bootstrap.min.js"></script>
<script src="js/highlight.pack.js"></script>
<script>
    $('div.sql').each(function(i, block) {
        hljs.highlightBlock(block);
    });
</script>
<script>
    function onSuccess(data) { $('#result').text(data['result']); }

    $('#format').click(function() {
        $.ajax({
            url: 'http://sqlformat.org/api/v1/format',
            type: 'POST',
            dataType: 'json',
            crossDomain: true,
            data: {sql: $('#sql').val(), reindent: 1},
            success: onSuccess,
        });
    })
</script>


</body>
</html>
