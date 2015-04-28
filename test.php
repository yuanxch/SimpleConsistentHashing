<?php

include "SimpleConsistentHashing.php";

$servers = array(
	'serv-1',
	'serv-2',
	'serv-3',
	'serv-4',
	'serv-5',
	'serv-6',
);





$counter = array();
for($i=0; $i<10000; $i++)
{
	$mod = round(hash("md5", $i) % 6);
	if(isset($counter[$mod]))
		$counter[$mod]++;	
	else
		$counter[$mod] = 1;
}

foreach($counter as $k=>$v) {
	echo $servers[$k] . "\t" . $v . "\t" . ($v/array_sum($counter)) . "%\n";
}

exit;







echo "========================\n";

$hasher = new SimpleConsistentHashing();
$hasher -> addTargets($servers, 1);


$counter = array();

for ($i=0; $i<10000; $i++)
{
	$h = hash("md5", $i);
	$targ = $hasher->lookup($h, 1);
	if(isset($counter[$targ])) 
		$counter[$targ]++;	
	else
		$counter[$targ] = 1;

}

foreach($counter as $k=>$v) {
	echo $k . "\t" . $v . "\t" . ($v/array_sum($counter)) . "%\n";
}


//ssssssssssssssssssssssssssssss
