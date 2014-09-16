<?php

require_once(l_r('objects/basic/set.php'));

/**
 * An object representing a relationship between a user and a game. Mostly contains
 * information used for printing the Game->summary(), when not loaded as userMember or
 * processMember
 *
 * @package Base
 * @subpackage Game
 */
class Member
{
	/**
	 * The member ID
	 * @var int
	 */
	var $id;
	/**
	 * The user ID
	 * @var int
	 */
	var $userID;
	/**
	 * The game ID
	 * @var int
	 */
	var $gameID;
	/**
	 * The countryID this member is playing as.
	 * @var int
	 */
	var $countryID;
	/**
	 * The country this member is playing as. Will be 'Unassigned' if pre-game.
	 * @var string
	 */
	var $country;
	/**
	 * The member status; 'Playing','Left','Defeated'
	 * @var string
	 */
	var $status;
	/**
	 * The username corresponding to this member
	 * @var string
	 */
	var $username;
	/**
	 * The number of points the user currently has available to bet
	 * @var int
	 */
	var $points;
	/**
	 * The amount the user bet into the game
	 * @var int
	 */
	var $bet;
	/**
	 * An array of countries from which this member has new messages. 'Global' may
	 * also be within this array.
	 *
	 * @var string[]
	 */
	var $newMessagesFrom;

	/**
	 * The time the player last logged into the game
	 *
	 * @var int
	 */
	var $timeLoggedIn;

	/**
	 * A link to the Game object this Member is a member of
	 *
	 * @var Game
	 */
	var $Game;

	/**
	 * The number of phases this Member has missed
	 *
	 * @var int
	 */
	var $missedPhases;

	/**
	 * The number of units this member owns
	 *
	 * @var int
	 */
	var $unitNo;

	/**
	 * The number of supply centers this member owns
	 * @var int
	 */
	var $supplyCenterNo;

	/**
	 * Whether this member is online or not
	 * @var bool
	 */
	var $online;

	/**
	 * An array of vote-flags which this member has voted for
	 *
	 * @var string[]
	 */
	var $votes;

	var $pointsWon;

	/**
	 * An array of the order status flags currently set: 'None','Saved','Completed','Ready'
	 *
	 * @var string[]
	 */
	var $orderStatus;

	/**
	 * A comma delimited list of the user's access permissions (used to determine what kind of donator the user is)
	 *
	 * @var string
	 */
	var $userType;

	/**
	 * Create a Member object from a database Member record row
	 * @param array $row Member record
	 */
	public function __construct($row)
	{
		foreach ( $row as $name => $value )
		{
			$this->{$name} = $value;
		}

		if( $this->countryID==0 )
			$this->country='Unassigned';
		else
			$this->country = $this->Game->Variant->countries[$this->countryID-1];

		// If making a userMember the $row is a userMember object not an array, and these operations have already been performed
		if ( ! $row instanceof Member )
		{
			if( strlen($this->votes) )
				$this->votes = explode(',', $this->votes);
			else
				$this->votes=array();

			if( strlen($this->newMessagesFrom) )
				$this->newMessagesFrom = explode(',', $this->newMessagesFrom);
			else
				$this->newMessagesFrom = array();

			$this->orderStatus=new setMemberOrderStatus($this->orderStatus);

			$this->online = (bool)$this->online;
		}
	}



	/**
	 * Generate a profile link
	 * @return string
	 */
	function profile_link()
	{
		if ( $this->Game->phase == 'Pre-game' )
		{
			$output = '<a href="profile.php?userID='.$this->userID.'">'.$this->username;
		}
		else
		{
			$output = '<a class="country'.$this->countryID.'" ';

			if ($this->status == 'Defeated')
			{
				$output .= 'style="text-decoration: line-through" ';
			}

			$output .= 'href="profile.php?userID='.$this->userID.'">'.$this->username;
		}
		return $output.' ('.$this->points.User::typeIcon($this->userType).')</a>';
	}

	function pointsValue()
	{
		return round($this->supplyCenterNo * $this->Game->Members->pointsPerSupplyCenter());
	}
	/**
	 * A textual display of this user's last log-in time
	 * @return string Last log-in time
	 */
	function lastLoggedInTxt()
	{
		return libTime::timeLengthText(time()-$this->timeLoggedIn).' ('.libTime::text($this->timeLoggedIn).')';
	}

	function send($keep, $private, $text, $fromCountryID=null)
	{
		notice::send(
			$this->userID, $this->gameID, 'Game',
			$keep, $private, $text, $this->Game->name, $this->gameID);
	}
}
?>
