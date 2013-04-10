<?php

$cfg = array(
	'in' => false,
	'json_out' => 'issues.json',
	'oauth' => false,
	'organization' => false,
	'out' => 'issues.csv',
	'pagination_page' => 1,
	'pagination_per_page' => 100,
	'password' => false,
	'repository' => false,
	'state' => 'open',
	'username' => false
);

foreach( $argv as $arg )
{
	foreach( $cfg as $k => $v )
	{
		$reg = sprintf( '/--%s=(.+)/', $k );
		if( preg_match( $reg, $arg, $match ))
		{
			$cfg[$k] = $match[1];
		}
	}
}

// Declare config as variables
foreach( $cfg as $cfgPath => $cfgValue )
{
	$$cfgPath = $cfgValue;
}

// Prepare some variables
$state = explode( ',', $state );

// Get issues json
if( $in && file_exists($in))
{
	$json = file_get_contents($in);
}
else
{
	if( ! $repository )
	{
		print "Repository required.\n";
		exit;
	}

	if( ! $oauth && ! $username )
	{
		print "OAuth token or username required.\n";
		exit;
	}

	if( ! $organization )
	{
		if( ! $username )
		{
			print "Organization required.\n";
			exit;
		}

		$organization = $username;
	}

	if( $username && ! $password )
	{
		printf( "Enter host password for user '%s':", $username );
		system( 'stty -echo' );
		$password = trim(fgets(STDIN));
		system('stty echo');
		print "\n";

		if( ! $password )
		{
			print "Password required with username.\n";
			exit;
		}
	}

	// Configure curl and post
	$url = sprintf(
		"https://api.github.com/repos/%s/%s/issues?page=%d&per_page=%d",
		$organization,
		$repository,
		$pagination_page,
		$pagination_per_page
	);

	$curl = curl_init();
	curl_setopt( $curl, CURLOPT_URL, $url );
	curl_setopt( $curl, CURLOPT_HEADER, false );
	curl_setopt( $curl, CURLOPT_USERPWD, $username.':'.$password );
	curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );

	$json = curl_exec($curl);

	// Back up json
	$handle = fopen( $json_out, 'w+' );
	if($handle)
	{
		fwrite( $handle, $json );
		fclose($handle);
	}
}

try
{
	$issues = json_decode($json);
}
catch( Exception $e )
{
	print $e->getMessage();
	exit;
}

if( is_object($issues) && isset($issues->message))
{
	printf( "Response from GitHub: %s\n", $issues->message );
	exit;
}

if( ! $issues )
{
	print "Error: could not interpret issues JSON correctly.\n";
	exit;
}

// Open export file handle
$handle = fopen( $out, 'w+' );
if( ! $handle )
{
	printf( "Unable to write to %s.\n", $out );
	exit;
}

$lineTemplate = array(
	'id' => null,
	'url' => null,
	'title' => null,
	'body' => null,
	'created_by' => null,
	'assigned_to' => null,
	'defcon' => null,
	'labels' => null,
	'milestone' => null	
);

fputcsv( $handle, array_keys($lineTemplate));

foreach( $issues as $issue )
{
	if( ! in_array( $issue->state, $state )) continue;

	$line = array_merge( $lineTemplate, array(
		'id' => $issue->number,
		'url' => $issue->html_url,
		'title' => $issue->title,
		'body' => $issue->body,
		'created_by' => $issue->user->login
	));

	if( $issue->assignee )
	{
		$line['assigned_to'] = $issue->assignee->login;
	}

	if( $issue->labels )
	{
		$labels = array();
		foreach( $issue->labels as $label )
		{
			// DEFCON issues extracted to separate column for sorting
			if( preg_match( '/defcon/i', $label->name ))
			{
				preg_match( '/[0-9]$/', $label->name, $defcon );
				$line['defcon'] = $defcon[0];
				continue;
			}

			$labels[] = $label->name;
		}

		asort($labels);
		$line['labels'] = implode( ', ', $labels );
	}

	if( $issue->milestone )
	{
		$line['milestone'] = $issue->milestone->title;
	}

	fputcsv( $handle, $line );
}

fclose($handle);
printf( "Finished writing %d issues to %s.\n", count($issues), $out );
