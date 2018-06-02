<?php
/******************************************************************************
 * phpGridServer
 *
 * GNU LESSER GENERAL PUBLIC LICENSE
 * Version 2.1, February 1999
 *
 */

require_once("lib/services.php");

if(function_exists("apache_request_headers"))
{
	$headers = apache_request_headers();
	if(isset($headers["X-SecondLife-Shard"]))
	{
		http_response_code("400");
		exit;
	}
}

$_AGENT_POST = require_once("lib/rpc/agentpost.php");

/* this is the only needed data, we need to know for actually relaying the home agent correctly */
$serverDataUri = getServerDataFromAgentData($_AGENT_POST);

if($serverDataUri->isHome())
{
	/* sims retry the home agents on the foreignagent path, so we relay that to homeagent implementation */
	return require_once("rest_homeagent.php");
}
else
{
	$serializer = new JSONHandler();
	/* this is now what we need for the foreignagent path, homeagent relay does it own initialization */

	function DoAgentResponse($success, $reason)
	{
		global $serializer;
		$res = new RPCSuccessResponse();
		$res->Params[] = new RPCStruct();
		$res->Params[0]->success = $success;
		$res->Params[0]->reason = "".$reason;
		$res->Params[0]->your_ip = getRemoteIpAddr();
		header("Content-Type: application/json");
		echo $serializer->serializeRPC($res);
		exit;
	}

	/* we reference the services here */
	$hgServerDataService = getService("HGServerData");
	$userAccountService = getService("UserAccount");
	$gridUserService = getService("GridUser");
	$presenceService = getService("Presence");
	$gridService = getService("Grid");
	$serverParamService = getService("ServerParam");

	/* we take the remaining data here */
	$userAccount = getUserAccountFromAgentData($_AGENT_POST);
	$sessionInfo = getSessionInfoFromAgentData($_AGENT_POST);
	
	/* from going through opensim code, it seems that it makes problems to have colliding UUIDs than it is worth about handling those on the sim */
	/* that would require a proxy for separation */
	try
	{
		$userAccountService->getAccountByID(null, $userAccount->PrincipalID);
		trigger_error("UUID collision with Foreign Agent and Home Agent (Origin Grid: ".$serverDataUri->HomeURI.")");
		DoAgentResponse(False, "UUID collision detected");
	}
	catch(Exception $e)
	{
	}
}

/* we load that PHP file here since we do not need that on the homeagent relaying the sims do */
require_once("lib/connectors/hypergrid/UserAgentRemoteConnector.php");

$userAgentConnector = new UserAgentRemoteConnector($serverDataUri->HomeURI);

$lockedmsg = $serverParamService->getParam("lockmessage", "");
if($lockedmsg != "")
{
	DoAgentResponse(False, $lockedmsg);
}

$lockedmsg = $serverParamService->getParam("lockmessage_".$userAccount->PrincipalID, "");
if($lockedmsg != "")
{
	DoAgentResponse(False, $lockedmsg);
}

/* verify user first before we store anything */
$servicesessionid = explode(";", $sessionInfo->ServiceSessionID);
if(count($servicesessionid) != 2)
{
	trigger_error("Failed to verify user identity (".$userAccount->PrincipalID.",".$serverDataUri->HomeURI."). Invalid Service Session ID: ".$sessionInfo->ServiceSessionID);
	DoAgentResponse(False, "Failed to verify user identity (Code 1)");
}
$servicesessionid[0] = ServerDataURI::appendPortToURI($servicesessionid[0]);

$homeGrid = ServerDataURI::getHome();

if($homeGrid->GatekeeperURI != $servicesessionid[0])
{
	trigger_error("Failed to verify user identity (".$userAccount->PrincipalID.",".$serverDataUri->HomeURI."). Invalid grid Name in ServiceSessionID: ".$homeGrid->GatekeeperURI." != ${servicesessionid[0]}");
	DoAgentResponse(False, "Failed to verify user identity (Code 2)");
}

try
{
	if(!$userAgentConnector->verifyAgent($sessionInfo->SessionID, $sessionInfo->ServiceSessionID))
	{
		throw new Exception("Failed to verify here");
	}
}
catch(Exception $e)
{
	trigger_error("Failed to verify user identity (".$userAccount->PrincipalID.",".$serverDataUri->HomeURI."). Agent verification failed:".get_class($e).".:".$e->getMessage());
	DoAgentResponse(False, "Failed to verify user identity (Code 3)");
}

try
{
	if(!$userAgentConnector->verifyClient($sessionInfo->SessionID,$clientInfo->ClientIP))
	{
		throw new Exception();
	}
}
catch(Exception $e)
{
	trigger_error("Failed to verify user identity (".$userAccount->PrincipalID.",".$serverDataUri->HomeURI."). Client IP is not valid:".get_class($e).".:".$e->getMessage());
	DoAgentResponse(False, "Failed to verify user identity (Code 4)");
}

/* now we know that we got a fully authenticated agent coming around */

try
{
	/* update Server URI when a new agent arrives */
	$hgServerDataService->storeServerURI($serverDataUri);
}
catch(Exception $e)
{
	trigger_error("Failed to store HyperGrid Server URIs (".$userAccount->PrincipalID.",".$serverDataUri->HomeURI."): ".get_class($e).".:".$e->getMessage());
	DoAgentResponse(False, "Failed to store HyperGrid Server URIs");
}

try
{
	/* that is a foreign agent coming in */
	/* we have to replace the destination info */
	$regionInfo = $gridService->getRegionByUuid(null, $destination->ID);
	$destination = DestinationInfo::fromRegionInfo($regionInfo);
	$destination->LocalToGrid = True;
        $destination->TeleportFlags |= TeleportFlags::ViaHGLogin;
	for($i = 0; $i < count($_AGENT_POST); ++$i)
	{
		if($_AGENT_POST[$i] instanceof DestinationInfo)
		{
			$_AGENT_POST[$i] = $destination; /* replace with Grid local destination info */
		}
		if($_AGENT_POST[$i] instanceof CircuitInfo)
		{
			$_AGENT_POST[$i]->Destination = $destination; /* replace with Grid local destination info */
		}
	}
}
catch(Exception $e)
{
	trigger_error("Incoming foreign agent (".$userAccount->PrincipalID.",".$serverDataUri->HomeURI."): Could not get target region information ".$e->getMessage()." ; ".get_class($e));
	DoAgentResponse(False, "Could not retrieve target region information. ".trim($e->getMessage()));
}

/* filter @ */
while(substr($userAccount->LastName, 0, 1) == "@")
{
	$sp = explode("@", $userAccount->FirstName, 2);
	$sp = explode(".", $sp[0], 2);
	if(count($sp) == 1)
	{
		$userAccount->FirstName = $sp;
		$userAccount->LastName = "";
	}
	else
	{
		$userAccount->FirstName = $sp[0];
		$userAccount->LastName = $sp[1];
	}
}

$userAccount->FirstName = trim($userAccount->FirstName);
$userAccount->LastName = trim($userAccount->LastName);

/* we have to add a Presence and we need that GridUser entry */
/* the following is the UUI we use within GridUserInfo */
$UUI = $userAccount->PrincipalID.";".$serverDataUri->HomeURI.";".$userAccount->FirstName." ".$userAccount->LastName;

$presence = new Presence();
$presence->UserID = $UUI;
$presence->SessionID = $sessionInfo->SessionID;
$presence->SecureSessionID = $sessionInfo->SecureSessionID;
$presence->ClientIPAddress = $clientInfo->ClientIP;
$presence->RegionID = $destination->ID;

try
{
	$presenceService->loginPresence($presence);
}
catch(Exception $e)
{
	trigger_error("Failed to add presence (".$userAccount->PrincipalID.",".$serverDataUri->HomeURI.")");
	$gridUserService->loggedOut($UUI);
	DoAgentResponse(False, "Failed to add Presence at target grid");
}

try
{
	$gridUserService->loggedIn($UUI);
}
catch(Exception $e)
{
	trigger_error("Failed to add GridUser (".$userAccount->PrincipalID.",".$serverDataUri->HomeURI.")");
	$presenceService->logoutPresence($sessionInfo->SessionID);
	DoAgentResponse(False, "Failed to add GridUser at target grid");
}

try
{
	$gridUser = $gridUserService->getGridUserHG($userAccount->PrincipalID);
}
catch(Exception $e)
{
	trigger_error("Failed to verify GridUser (".$userAccount->PrincipalID.",".$serverDataUri->HomeURI.") ".$e->getMessage());
	$presenceService->logoutPresence($sessionInfo->SessionID);
	DoAgentResponse(False, "Failed to verify GridUser");
}

if(substr("".$userAccount->LastName, 0, 1) == "@")
{
	/* do not replace the name in this case */
}
else
{
	$userAccount->FirstName = $userAccount->FirstName.".".$userAccount->LastName;
}

$uricomponents = parse_url($serverDataUri->HomeURI);
if(!isset($uricomponents["port"]))
{
	$userAccount->LastName = "@".$uricomponents["host"];
}
else if($uricomponents["port"] == 80)
{
	$userAccount->LastName = "@".$uricomponents["host"];
}
else
{
	$userAccount->LastName = "@".$uricomponents["host"].":".$uricomponents["port"];
}


try
{
	$launchAgentService = getService("LaunchAgent");
	$circuitInfo = $launchAgentService->launchAgent($_AGENT_POST);
}
catch(Exception $e)
{
	$msg = $e->getMessage();
	trigger_error("Failed to launch foreign agent (".$userAccount->PrincipalID.",".$serverDataUri->HomeURI.") ".get_class($e).":".$msg);
	try
	{
		$gridUserService->loggedOut($UUI);
	}
	catch(Exception $ex) {}
	try
	{
		$presenceService->logoutPresence($sessionInfo->SessionID);
	}
	catch(Exception $ex) {}

	DoAgentResponse(False, trim($msg));
}

DoAgentResponse(True, "authorized");
