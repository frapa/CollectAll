<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require('query.php');

// Setup database
$db = new PDO('sqlite:'. getcwd() . '/test.sqlite');
Collection::setPDO($db);

$users = All('Users');

var_dump(count($users));
foreach ($users as $user) {
    //var_dump($user->name, $user->countries->iso_code);
}

$country = $users->limit(1)->countries;
var_dump($country->iso_code);
#$country->iso_code = 'JP';
#$country->save();

echo "<br>-----------<br>";
foreach ($users as $user) {
    //$user->age -= 1;
    echo $user->name . " (" . $user->name . "):<br>";
    $tasks = $user->tasks;
    
    foreach ($tasks as $task) {
        echo " - " . $task->description . "<br>";
    }
}

/*$fra = $users->createNew([
    'name' => 'Francesco',
    'age' => 23
]);*/

$fra = $users->filter('name', '=', 'Francesco');

$task = All('Tasks')->filter('id', '=', 1);

$fra->link($country);
//$fra->unlink($country);
$fra->link($task);
//$fra->unlink($task);

//$fra->delete();

$users->save();
