<?php
/******************************************************************************
 * phpGridServer
 *
 * GNU LESSER GENERAL PUBLIC LICENSE
 * Version 2.1, February 1999
 *
 */

if(!isset($_RPC_REQUEST->userID))
{
	trigger_error("userID missing");
	http_response_code("400");
	exit;
}

if(!isset($_POST["online"]))
{
	trigger_error("online not filled in correctly");
	http_response_code("400");
	exit;
}

if(!UUID::IsUUID($_RPC_REQUEST->userID))
{
	trigger_error("invalid UUID");
	http_response_code("400");
	exit;
}

$friends = array();

foreach($_RPC_REQUEST->Params as $k => $v)
{
	if(substr($k, 0, 7) == "friend_")
	{
		$friends[] = $v;
	}
}

try
{
	$onlineFriends = $HGFriendsService->statusNotification($friends, $_RPC_REQUEST->userID, string2boolean($_RPC_REQUEST->online));
}
catch(Exception $e)
{
	trigger_error("failed on statusnotification ".get_class($e).";".$e->getMessage());
	header("Content-Type: text/xml");
	echo "<?xml version=\"1.0\" encoding=\"utf-8\"?>";
	echo "<ServerResponse><RESULT>NULL</RESULT></ServerResponse>";
	exit;
}

/* enable output compression */
if(!isset($_GET["rpc_debug"]))
{
	ini_set("zlib.output_compression", 4096);
}

header("Content-Type: text/xml");
echo "<?xml version=\"1.0\" encoding=\"utf-8\"?>";
echo "<ServerResponse>";
if(count($onlineFriends))
{
	$cnt = 0;
	foreach($onlineFriends as $v)
	{
		echo "<friend_$cnt>".xmlentities(substr($v, 0, 36))."</friend_$cnt>";
		++$cnt;
	}
}
else
{
	echo "<RESULT>NULL</RESULT>";
}
echo "</ServerResponse>";
