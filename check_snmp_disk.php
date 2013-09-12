#!/usr/bin/php
<?php

class Check_SNMP_Disk
{
	private $hrStorage = '.1.3.6.1.2.1.25.2.3.1';
	private $Index = '.1';
	private $Type = '.2';
	private $Descr = '.3';
	private $AllocationUnits = '.4';
	private $Size = '.5';
	private $Used = '.6';
	private $AllocationFailures = '.7';

	private static $terabyte = 1099511627776;
	private static $gigabyte = 1073741824;
	private static $megabyte = 1048576;
	private static $kilobyte = 1024;

	public $hostname;
	public $community = 'public';
	public $id;
	public $warn = 0;
	public $critical = 0;

	public $d;
	public $a;
	public $s;
	public $u;
	public $f;
	public $uom;
	public $pu;
	public $pf;

	public static function convertValue(&$value)
	{
		if (preg_match('/^INTEGER: /', $value)) {
			$value = (int)preg_replace('/^INTEGER: /', '', $value);
		} else if (preg_match('/^STRING: /', $value)) {
			$value = (string)preg_replace('/^STRING: /', '', $value);
		}
	}

	public function doSNMPGet($id)
	{
		$this->d = snmpget($this->hostname, $this->community, $this->hrStorage . $this->Descr .'.'. $id);
		$this->a = snmpget($this->hostname, $this->community, $this->hrStorage . $this->AllocationUnits .'.'. $id);
		$this->s = snmpget($this->hostname, $this->community, $this->hrStorage . $this->Size .'.'. $id);
		$this->u = snmpget($this->hostname, $this->community, $this->hrStorage . $this->Used .'.'. $id);

		Check_SNMP_Disk::convertValue($this->d);
		Check_SNMP_Disk::convertValue($this->a);
		Check_SNMP_Disk::convertValue($this->s);
		Check_SNMP_Disk::convertValue($this->u);

		$this->d = explode(' ', $this->d);
		$this->d = $this->d[0];

		$this->s = $this->s * $this->a;
		$this->u = $this->u * $this->a;

		if ($this->s > self::$terabyte) {
			$this->s = round($this->s / self::$terabyte, 2);
			$this->u = round($this->u / self::$terabyte, 2);
			$this->uom = 'TB';
		} else if ($this->s > self::$gigabyte) {
			$this->s = round($this->s / self::$gigabyte, 2);
			$this->u = round($this->u / self::$gigabyte, 2);
			$this->uom = 'GB';
		} else if ($this->s > self::$megabyte) {
			$this->s = round($this->s / self::$megabyte, 2);
			$this->u = round($this->u / self::$megabyte, 2);
			$this->uom = 'MB';
		} else if ($this->s > self::$kilobyte) {
			$this->s = round($this->s / self::$kilobyte, 2);
			$this->u = round($this->u / self::$kilobyte, 2);
			$this->uom = 'KB';
		} else {
			$this->uom = 'B';
		}

		$this->f = $this->s - $this->u;

		$this->pu = round(($this->u / $this->s) * 100,0);
		$this->pf = round(($this->f / $this->s) * 100,0);
	}

	public function viewStorageTable()
	{
		$storageIndexes = snmpwalkoid($this->hostname, $this->community, $this->hrStorage . $this->Index);

		array_walk($storageIndexes, 'Check_SNMP_Disk::convertValue');

		foreach ($storageIndexes as $storageIndex) {
			$this->doSNMPGet($storageIndex);

			if ($this->s == 0) continue;

			printf("%d: %s - Total: %g %s - Used: %g %s (%d%%) - Free: %g %s (%d%%)\n",
					$storageIndex,
					$this->d,
					$this->s,
					$this->uom,
					$this->u,
					$this->uom,
					$this->pu,
					$this->f,
					$this->uom,
					$this->pf);
		}

		exit(0);
	}

	public function reportStorageId()
	{
		$this->doSNMPGet($this->id);

		if (($this->critical > 0) && ($this->pu > $this->critical)) {
			$printStatus = 'Critical';
			$exitStatus = 2;
		} else if (($this->warn > 0) && ($this->pu > $this->warn)) {
			$printStatus = 'Warning';
			$exitStatus = 1;
		} else {
			$printStatus = 'OK';
			$exitStatus = 0;
		}

		$this->warn = round($this->s * ($this->warn / 100), 2);
		$this->critical = round($this->s * ($this->critical / 100), 2);

		printf("%s - %s - Total: %g %s - Used: %g %s (%d%%) - Free: %g %s (%d%%)|'%s Used Space'=%g%s;%g;%g;0.00;%g\n",
			$printStatus,
			$this->d,
			$this->s,
			$this->uom,
			$this->u,
			$this->uom,
			$this->pu,
			$this->f,
			$this->uom,
			$this->pf,
			$this->d,
			$this->u,
			$this->uom,
			$this->warn,
			$this->critical,
			$this->s);

		exit($exitStatus);
	}

	public function help()
	{
		exit('Usage: check_snmp_disk -H <hostname> -C <community> [-i <id>] [-w <warn>] [-c <critical>]
				-H <hostname>                 - server name to query (ie. server.localdomain.com)
				-C <community>                - community name for the SNMP query
				[-i <id>]                     - index into the storage table to report
				[-w <warn>]                   - warning percentage level
				[-c <critical>]               - critical percentage level'."\n\n");
	}

	public function run()
	{
		$options = getopt('H:C:i:w:c:h:');

		foreach ($options as $flag => $parameter) {

			switch($flag) {
				case 'H':
					$this->hostname = $parameter;
					break;

				case 'C':
					$this->community = $parameter;
					break;

				case 'i':
					$this->id = $parameter;
					break;

				case 'w':
					$this->warn = $parameter;
					break;

				case 'c':
					$this->critical = $parameter;
					break;

				case 'h':
					$this->help();
					break;
			}
		}

		if (isset($this->hostname) && isset($this->community) && isset($this->id)) {
			$this->reportStorageId();
		} else if (isset($this->hostname) && isset($this->community)) {
			$this->viewStorageTable();
		} else {
			$this->help();
		}
	}
}

$Check_SNMP_Disk = new Check_SNMP_Disk();
$Check_SNMP_Disk->run();
