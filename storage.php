<?php


function getVGs()
	{
	$Cmd = 'vgs -v --separator , --unit G --noheadings --unbuffered';
	$Str = '';
	exec( $Cmd, $Str );
	$VGs = array();
	foreach ($Str as $Line)
		{
		if (strpos( $Line, 'Finding' ) !== false)
			continue;
		$Parts = explode( ',', trim( $Line ) );
		$Uuid = $Parts[8];
		$VGs[$Uuid] = $Parts[0];
		}
	return $VGs;
	}
function lookupVgUuid( $VGs, $Name )
	{
	foreach ($VGs as $UUID => $VG)
		{
		if ($VG == $Name)
			return $UUID;
		}
	throw new Exceptin( 'Could not find the VG' );
	}


function getPVs()
	{
	$Cmd = 'pvs -v --separator , --unit G --noheadings --unbuffered';
	$Str = '';
	exec( $Cmd, $Str );
	$PVs = array();
	foreach ($Str as $Line)
		{
		if (strpos( $Line, 'Scanning' ) !== false)
			continue;
		$Parts = explode( ',', trim( $Line ) );
		$UUID = $Parts[7];
		$PVs[$UUID] = array( $Parts[0], $Parts[1] );;
		}
	return $PVs;
	}


function getLVs()
	{
	$Cmd = 'lvs -v --separator , --unit G --noheadings --unbuffered';
	$Str = '';
	exec( $Cmd, $Str );
	$LVs = array();
	foreach ($Str as $Line)
		{
		if (strpos( $Line, 'Finding' ) !== false)
			continue;
		$Parts = explode( ',', trim( $Line ) );
		$Uuid = $Parts[17];
		$LVs[$Uuid] = array( $Parts[0], $Parts[1], $Uuid );
		}
	return $LVs;
	}
function lookupLvUuid( $LVs, $VG, $LvName )
	{
	foreach ($LVs as $Uuid => $LV)
		{
		list ($Name, $v) = $LV;
		if ($Name == $LvName && $v == $VG)
			return $Uuid;
		}
	return false;
	}


function getVMs()
	{
	$VMs = [];
	foreach (glob( '/etc/libvirt/qemu/*.xml') as $VMF)
		{
		$XML = simplexml_load_file( $VMF );
//		print_r( $XML );
//		echo "VM\n";
		$Name = (string) $XML->name;
		$VMs[$Name] = [];
		$VMs[$Name] = [];
		foreach ($XML->devices->disk as $D)
			{
//			echo "DISK\n";
			if ($D['type'] != 'block')
				{
//				TODO: Warn about this.
//				echo "Not a block device.\n";
				continue;
				}
//			print_r( $D );
			$sourceDev = (string) $D->source['dev'];
			if (strpos( $sourceDev, 'mapper' ) !== false)
				{
//				echo "before $sourceDev\n";
				$sourceDev = preg_replace( '#mapper/([^-]*)-#', '$1/', $sourceDev );
//				echo "after  $sourceDev\n";
				}
			$TargetDev = (string) $D->target['dev'];
			$VMs[$Name][$sourceDev] = $TargetDev;
//			echo "DEV $SourceDev -> $TargetDev\n";
			}
		}
	return $VMs;
	}




$VGs = getVGs();
$PVs = getPVs();
$LVs = getLVs();
$VMs = getVMs();
//print_r( $VMs );


function renderVGs( $VGs )
	{
	foreach ($VGs as $Uuid => $VG)
		{
		$U = substr( $Uuid, 0, 6 );
		echo "\"$Uuid\" [label=\"VG $U $VG\"];\n";
		}
	}


function renderPVs( $PVs, $VGs )
	{
	foreach ($PVs as $Uuid => $PV)
		{
		list ($Name, $VgName) = $PV;
		$VgUuid = lookupVgUuid( $VGs, $VgName );
		$U = substr( $Uuid, 0, 6 );
		echo "\"$Uuid\" [label=\"PV $U $Name\"];\n";
		echo "\"$Uuid\" -> \"$VgUuid\";\n";
		}
	}


function renderLVs( $LVs, $VGs )
	{
	foreach ($LVs as $Uuid => $LV)
		{
		list ($Name,$VgName) = $LV;
		$VgUuid = lookupVgUuid( $VGs, $VgName );
		$U = substr( $Uuid, 0, 6 );
		echo "\"$Uuid\" [label=\"LV $U $Name\"];\n";
		echo "\"$VgUuid\" -> \"$Uuid\";\n";
		}
	}


function renderVMs( $VMs, $LVs )
	{
	foreach ($VMs as $N => $Disks)
		{
		$Name = str_replace( '-', '_', $N );
		$Deleted = strpos( $Name, '_deleted' ) !== false;
		$C = $Deleted ? 'grey' : 'black';
		echo "subgraph cluster_$Name {\n";
		echo "color = $C;\n";
		echo "fontcolor = $C;\n";
		echo "label = \"VM $Name\";\n";
		foreach ($Disks as $Source => $Target)
			{
			$u = $Name . '_' . $Source;
			$v = $Name . '_' . $Target;
			echo "\"$u\" [label=\"$Source\" color=$C fontcolor=$C];\n";
			echo "\"$v\" [label=\"$Target\" color=$C fontcolor=$C];\n";
			echo "\"$u\" -> \"$v\" [$C];\n";
			}
		echo " }\n";
		// outside cluster
		foreach ($Disks as $Source => $Target)
			{
			$u = $Name . '_' . $Source;
			$parts = explode( '/', $Source );
			$vg = $parts[2];
			$name = $parts[3];
			$LvUuid = lookupLvUuid( $LVs, $vg, $name );
			$C = $Deleted ? '[color=grey]' : '';
			if ($LvUuid !== false)
				echo "\"$LvUuid\" -> \"$u\" $C;\n";
//			else
//				echo "DD uuid ($LvUuid) is not falsey\n";
			}
		echo "\n";
		}
	}


echo "digraph storage {\n";
echo "rankdir=LR;\n";
renderPVs( $PVs, $VGs );
renderVGs( $VGs );
renderLVs( $LVs, $VGs );
renderVMs( $VMs, $LVs );
echo "}\n";

