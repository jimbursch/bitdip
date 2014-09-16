<?php

defined('IN_CODE') or die('This script can not be run by itself.');

/**
 * A class which specifies phase specific requirements and options for order forms. See
 * the iUserOrder interface for documentation.
 * @package Board
 * @subpackage Orders
 */
class userOrderDiplomacy extends userOrder
{
	public function __construct($orderID, $gameID, $countryID)
	{
		parent::__construct($orderID, $gameID, $countryID);

		$this->fixed = array('unitID');
		$this->requirements = array('type');
	}

	protected function updaterequirements()
	{
		switch($this->type)
		{
			case 'Move':
				$this->requirements=array('type','toTerrID','viaConvoy');
				break;
			case 'Support hold':
				$this->requirements=array('type','toTerrID');
				break;
			case 'Support move':
			case 'Convoy':
				$this->requirements=array('type','toTerrID','fromTerrID');
				break;
		}
	}

	protected function typeCheck()
	{
		switch($this->type) {
			case 'Hold':
			case 'Move':
			case 'Support hold':
			case 'Support move':
				return true;
			case 'Convoy':
				if ( $this->Unit->type == 'Fleet' and $this->Unit->Territory->type == 'Sea' )
					return true;
			default:
				return false;
		}
	}

	protected function toTerrIDCheck()
	{
		$this->toTerrID=(int)$this->toTerrID;

		switch($this->type)
		{
			case 'Move':
				return $this->moveToTerrCheck();
			case 'Support hold':
				return $this->supportHoldToTerrCheck();
			case 'Support move':
				return $this->supportMoveToTerrCheck();
			case 'Convoy':
				return $this->convoyToTerrCheck();
			default:
				throw new Exception(l_t("Unrequired to-territory check for this order type '%s'",$this->type));
		}
	}

	protected function fromTerrIDCheck()
	{
		$this->fromTerrID=(int)$this->fromTerrID;

		switch($this->type)
		{
			case 'Support move':
				return $this->supportMoveFromTerrCheck();
			case 'Convoy':
				return $this->convoyFromTerrCheck();
			default:
				throw new Exception(l_t("Unrequired from-territory check for this order type '%s'",$this->type));
		}
	}

	protected $viaConvoyOptions=array(); // Check are collected into this array while generating toTerrID options
	protected function viaConvoyCheck()
	{
		if( count($this->viaConvoyOptions)==0 )
		{
			/*
			 * viaConvoyOptions are generated from within toTerrCheck() -> moveToTerrCheck(),
			 * which is only triggered when the position being moved to gets altered.
			 *
			 * This means if someone moves to somewhere that requires a via convoy parameter,
			 * but didn't choose initially, or they chose but then changed their choice,
			 * moveToTerrCheck won't be re-run (since the position being moved to is already
			 * valid), and so the viaConvoyOptions won't be generated.
			 *
			 * This will result in $this->viaConvoyOptions have nothing in it, and so we need
			 * to generate the viaConvoyOptions.
			 *
			 * The simplest way to do this is to call $this->moveToTerrCheck(). It will return
			 * true since we have already reached this point, but it has the desired side-effect
			 * of generating the viaConvoyOptions.
			 *
			 * (Bug pointed out by Mike Cheng 08/5/2011)
			 */

			$this->moveToTerrCheck();
		}

		return in_array($this->viaConvoy, $this->viaConvoyOptions);
	}

	protected $convoyPath=array();

	/**
	 * Check the supplied convoyPath generated by the client-side order-generating JavaScript. For orders which
	 * contain convoys the convoyPath will contain a chain of territory IDs, starting with the first coast, and
	 * ending with the final fleet in the convoy.
	 *
	 * Checking this given path is much more efficient and takes much less code than generating the intermediate
	 * path server-side, but because it comes from the client it must be checked. It is already loaded into
	 * $this->convoyPath and has been converted to an array of integers.
	 *
	 * @param int $startCoastTerrID The terrID of the coast with an army in it, which is being convoyed (also at convoyGroup[0]
	 * @param int $endCoastTerrID The terrID of the end coast being convoyed to (not contained in convoyGroup)
	 * @param int $mustContainTerrID A terrID which convoyGroup must contain somewhere (e.g. a convoying fleet)
	 * @param int $mustNotContainTerrID A terrID which convoyGroup must not contain (e.g. a support-moving fleet which mustn't
	 * 		be within the convoy. The client-side JS knows not to submit a convoy chain containing the support-moving fleet)
	 *
	 * @return boolean True if valid, false otherwise
	 */
	protected function checkConvoyPath($startCoastTerrID, $endCoastTerrID, $mustContainTerrID=false, $mustNotContainTerrID=false) {
		global $DB;

		if( count($this->convoyPath)<2 ) // First, plus one fleet, then $endCoastTerrID makes the minimum 3
			return false; // Not enough units in the convoyPath to be valid

		if( $this->convoyPath[0]!=$startCoastTerrID )
			return false; // Doesn't start in the right place

		if( $mustContainTerrID && !in_array($mustContainTerrID, $this->convoyPath) )
			return false; // Contains a terrID that it mustn't (a fleet supporting a move, typically)

		if( $mustNotContainTerrID && in_array($mustNotContainTerrID, $this->convoyPath) )
			return false; // Doesn't contain a terrID that it must (a fleet convoying a unit)

		static $validConvoyPaths;
		if( !isset($validConvoyPaths) )
			$validConvoyPaths=array();
		elseif( in_array($startCoastTerrID.'-'.$endCoastTerrID, $validConvoyPaths) )
			return true;

		/*
		 * The first convoyPath entry is the starting coast with the army.
		 * [ $this->convoyPath[0], $this->convoyPath[1], $this->convoyPath[2], ..., $endFleetTerrID, $endCoastTerrID ]
		 *
		 * The start and end IDs will always be available to be checked e.g. as the terrID/toTerrID/fromTerrID,
		 * all that needs to be checked is that the given convoyPath represents an unbroken chain of fleets at sea from
		 * the start to the end
		 *
		 * With this checked other checks (e.g. whether the path contains a certain fleet or not) can be done independantly.
		 */
		$borderLinks=array();
		for($i=1; $i<count($this->convoyPath); $i++)
		{
			$fromTerrID=$this->convoyPath[$i-1];
			$toTerrID=$this->convoyPath[$i];
			$borderLinks[] = "b.fromTerrID=".$fromTerrID." AND b.toTerrID=".$toTerrID;
		}
		$endFleetTerrID=$toTerrID;

		$borderLinks='('.implode(') OR (',$borderLinks).')';

		/*
		 * - The first select checks that an army is in the starting position.
		 * - The second union select checks all the intermediate fleets in the chain
		 * connecting the start coast to end coast.
		 * - The third union select checks that the final territory is a coast.
		 *
		 * Altogether these check the whole convoyPath, if the right number of rows
		 * are returned the given convoyPath must be a valid convoy-chain linking the
		 * start and end coasts.
		 */
		$tabl=$DB->sql_tabl(
			"SELECT terrID FROM wD_Units
			WHERE gameID=".$this->gameID." AND type='Army' AND terrID=".$startCoastTerrID."

			UNION SELECT b.toTerrID
			FROM wD_Borders b
			INNER JOIN wD_Units fleet
				ON ( fleet.gameID=".$this->gameID." AND fleet.terrID = b.toTerrID AND fleet.type='Fleet' )
			WHERE
				b.mapID=".MAPID." AND ".$borderLinks."
				AND b.armysPass='No' AND b.fleetsPass='Yes'

			UNION SELECT b.toTerrID
			FROM wD_Borders b INNER JOIN wD_Territories t ON (t.id=b.toTerrID)
			WHERE
				b.mapID=".MAPID." AND t.mapID=".MAPID."
				AND t.type='Coast'
				AND b.fromTerrID=".$endFleetTerrID." AND b.toTerrID=".$endCoastTerrID."
				AND b.armysPass='No' AND b.fleetsPass='Yes'");

		// Check the number of returned links, if it is the correct length the chain must be valid.
		$i=0;
		while($row=$DB->tabl_row($tabl)) $i++;

		if( $i==(count($this->convoyPath)+1) ) // convoyPath territories plus the end coast, which isn't included
		{
			$validConvoyPaths[]=$startCoastTerrID.'-'.$endCoastTerrID;
			return true; // Every convoyPath element was returned as expected
		}
		else
			return false; // Something is missing
	}

	/**
	 * Checks that toTerrID can be moved to
	 * @return boolean
	 */
	protected function moveToTerrCheck()
	{
		// Movable territories
		if( $result=$this->sqlCheck(
			"SELECT toTerrID
			FROM wD_CoastalBorders
			WHERE /* Moving from our position */
				mapID=".MAPID." AND
				fromTerrID = ".$this->Unit->terrID."
				/* Our unit type is allowed to move */
				AND ".strtolower($this->Unit->type)."sPass = 'Yes'
				AND toTerrID = ".$this->toTerrID."
			LIMIT 1") )
		{
			if ( !in_array('viaConvoy', $this->requirements) )
				return true;
			else
				$this->viaConvoyOptions[]='No';
		}
		else
		{
			if ( !in_array('viaConvoy', $this->requirements) )
				return false;
		}

		if( $this->checkConvoyPath($this->Unit->terrID, $this->toTerrID) )
		{
			$this->viaConvoyOptions[]='Yes';
			$result=true;
		}

		return $result;
	}

	/**
	 * Movable occupied territories, with coasts stripped off
	 *
	 * @return boolean
	 */
	protected function supportHoldToTerrCheck()
	{
		return $this->sqlCheck(
			"SELECT t.terrID
			FROM wD_CoastalBorders b
			INNER JOIN wD_TerrStatus t ON (
				/* Get TerrStatus to check that the territory is occupied */
				".libVariant::$Variant->deCoastCompare('t.terrID','b.toTerrID')."
				AND t.gameID = ".$this->gameID."
			)
			WHERE /* Moving from our position */
				b.mapID=".MAPID." AND
				fromTerrID = ".$this->Unit->terrID."
				/* Our unit type is allowed to move */
				AND ".strtolower($this->Unit->type)."sPass = 'Yes'
				AND t.occupyingUnitID IS NOT NULL
				AND ".$this->toTerrID." = t.terrID
			LIMIT 1"
		); // TODO: deCoastText($this->inputTerrID) required?
	}

	/**
	 * Movable territories, with coasts stripped off
	 *
	 * @return boolean
	 */
	protected function supportMoveToTerrCheck()
	{
		return $this->sqlCheck(
			"SELECT b.toTerrID
			FROM wD_Borders b
			WHERE
				b.mapID=".MAPID." AND
				/* Moving from our position */
				b.fromTerrID = ".$this->Unit->terrID."
				/* Our unit can move there */
				AND b.".strtolower($this->Unit->type)."sPass = 'Yes'
				AND ".libVariant::$Variant->deCoastCompareText($this->toTerrID,'b.toTerrID')."
			LIMIT 1"
		); // TODO: deCoastText($this->inputTerrID) required?
	}

	/**
	 * Territories adjacent to toTerrID, occupied by units which could move into toTerrID, not
	 * including Unit->terrID
	 *
	 * @return boolean
	 */
	protected function supportMoveFromTerrCheck()
	{
		// Check supporting a move from a convoying army, then check supports from local units
		if( count($this->convoyPath) && $this->checkConvoyPath($this->fromTerrID, $this->toTerrID, false, $this->Unit->terrID) )
			return true;
		elseif( $this->sqlCheck(
			"SELECT b.fromTerrID
			FROM wD_Borders b
			INNER JOIN wD_Units u
				/* A unit is on the fromTerrID position, .. */
				ON ( u.terrID = b.fromTerrID AND u.gameID = ".$this->gameID." )
			WHERE
				b.mapID = ".MAPID." AND
				/* .. bordering the toTerrID position, .. */
				".libVariant::$Variant->deCoastCompareText($this->toTerrID,'b.toTerrID')."
				/* .. the unit can move to the toTerrID position, .. */
				AND (
					( u.type = 'Army' AND b.armysPass = 'Yes' )
					OR
					( u.type = 'Fleet' AND b.fleetsPass = 'Yes' )
				)
				/* .. and the unit isn't the unit we're ordering. */
				AND NOT u.id = ".$this->unitID."
				AND ".libVariant::$Variant->deCoastCompareText($this->fromTerrID, 'b.fromTerrID')."
			LIMIT 1"
		) ) // TODO: deCoastText($this->inputTerrID) required?
			return true;
		else
			return false;
	}

	/**
	 * Coasts which are convoy-accessible by the army we are convoying from, and
	 * which go through us
	 *
	 * @return boolean
	 */
	protected function convoyToTerrCheck()
	{
		if( !isset($this->fromTerrID) )
			throw new Exception(l_t("Please submit only full convoys: Fleet at %s convoy to %s from ____.",$this->Unit->terrID,$this->toTerrID));
		else
			return true;
	}

	/**
	 * Army occupied coasts which are linked by a convoy chain
	 *
	 * @return boolean
	 */
	protected function convoyFromTerrCheck()
	{
		return $this->checkConvoyPath($this->fromTerrID, $this->toTerrID, $this->Unit->terrID, false);
	}
}

?>