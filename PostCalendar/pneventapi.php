<?php
require_once ('modules/PostCalendar/global.php');

/**
 *	SVN: $Id$
 *
 *	@package		 PostCalendar 
 *	@lastmodified	 $Date$ 
 *	@modifiedby		 $Author: craigh $ 
 *	@HeadURL		   $HeadURL$ 
 *	@version		 $Revision$ 
 *	
 *	PostCalendar::Zikula Events Calendar Module
 *	Copyright (C) 2002	The PostCalendar Team
 *	http://postcalendar.tv
 *	Copyright (C) 2009	Sound Web Development
 *	Craig Heydenburg
 *	http://code.zikula.org/soundwebdevelopment/
 *	
 *	This program is free software; you can redistribute it and/or modify
 *	it under the terms of the GNU General Public License as published by
 *	the Free Software Foundation; either version 2 of the License, or
 *	(at your option) any later version.
 *	
 *	This program is distributed in the hope that it will be useful,
 *	but WITHOUT ANY WARRANTY; without even the implied warranty of
 *	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *	GNU General Public License for more details.
 *	
 *	You should have received a copy of the GNU General Public License
 *	along with this program; if not, write to the Free Software
 *	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 *	To read the license please read the docs/license.txt or visit
 *	http://www.gnu.org/copyleft/gpl.html
 *
 */

/**
 * This is the event handler api
 **/

/**
 *	postcalendar_eventapi_queryEvents //new name
 *	Returns an array containing the event's information (plural or singular?)
 *	@params array(key=>value)
 *	@params string key eventstatus
 *	@params int value -1 == hidden ; 0 == queued ; 1 == approved
 *	@return array $events[][]
 */
function postcalendar_eventapi_queryEvents($args)
{	
	//echo "pcQuerydebug<br>";
	//pcDebugVar ($args);
	$end = '0000-00-00';
	extract($args);

	//CAH we should not be get form values in an API function
	$pc_username = FormUtil::getPassedValue('pc_username');
	$topic		 = FormUtil::getPassedValue('pc_topic');
	$category	 = FormUtil::getPassedValue('pc_category');
	$userid		 = pnUserGetVar('uid');

	if(!empty($pc_username) && (strtolower($pc_username) != 'anonymous')) {
		if($pc_username=='__PC_ALL__') {
			$ruserid = -1;
		} else {
			$ruserid = pnUserGetIDFromName(strtolower($pc_username));
		}
	}

	if(!isset($eventstatus) || ((int)$eventstatus < -1 || (int)$eventstatus > 1)) $eventstatus = 1;

	if(!isset($start)) $start = Date_Calc::dateNow('%Y-%m-%d'); 
	list($sy,$sm,$sd) = explode('-',$start);

	$where = "WHERE pc_eventstatus=$eventstatus 
						AND (pc_endDate>='$start' OR (pc_endDate='0000-00-00' AND pc_recurrtype<>'0') OR pc_eventDate>='$start')
						AND pc_eventDate<='$end' ";

	if(isset($ruserid)) {
		// get all events for the specified username
		if($ruserid == -1) {
			$where .= "AND (pc_sharing = '" . SHARING_BUSY . "' ";
			$where .= "OR pc_sharing = '" . SHARING_PUBLIC . "') ";
		} else {
			// v4b TS start - always see the records of the logged in user too | disabled on 2004-10-18
			$where .= "AND pc_aid = $ruserid ";
			//$where .= "AND (pc_aid = $ruserid OR pc_aid = $userid) ";
		}
	} else if (!pnUserLoggedIn()) {
		// get all events for anonymous users
		$where .= "AND (pc_sharing = '" . SHARING_GLOBAL . "' ";
		$where .= "OR pc_sharing = '" . SHARING_HIDEDESC . "') ";
	} else {
		// get all events for logged in user plus global events
		$where .= "AND (pc_aid = $userid OR pc_sharing = '" . SHARING_GLOBAL . "' OR pc_sharing = '" . SHARING_HIDEDESC . "') ";
	}


	// Start Search functionality 
	if(!empty($s_keywords)) $where .= "AND ($s_keywords) ";
	if(!empty($s_category)) $where .= "AND ($s_category) ";
	if(!empty($s_topic))	$where .= "AND ($s_topic) ";
	if(!empty($category))	$where .= "AND (tbl.pc_catid = '".pnVarPrepForStore($category)."') ";
	if(!empty($topic))		$where .= "AND (tbl.pc_topic = '".pnVarPrepForStore($topic)."') ";
	// End Search functionality 

	$sort .= "ORDER BY pc_meeting_id";

		// FIXME !!!
	$joinInfo = array ();
	$joinInfo[] = array (	'join_table'		  =>  'postcalendar_categories',
				'join_field'		  =>  'catname',
				'object_field_name'	  =>  'catname',
				'compare_field_table' =>  'catid',
				'compare_field_join'  =>  'catid');
	$joinInfo[] = array (	'join_table'		  =>  'postcalendar_categories',
				'join_field'		  =>  'catdesc',
				'object_field_name'	  =>  'catdesc',
				'compare_field_table' =>  'catid',
				'compare_field_join'  =>  'catid');
	$joinInfo[] = array (	'join_table'		  =>  'postcalendar_categories',
				'join_field'		  =>  'catcolor',
				'object_field_name'	  =>  'catcolor',
				'compare_field_table' =>  'catid',
				'compare_field_join'  =>  'catid');

	$events = DBUtil::selectExpandedObjectArray ('postcalendar_events', $joinInfo, $where, $sort);
	$topicNames = DBUtil::selectFieldArray ('topics', 'topicname', '', '', false, 'topicid');
	
	// added temp_meeting_id
	// this prevents duplicate display of same event for different participants
	// but is a bit of a strange way of doing it...
	$old_m_id = "NULL";
	//$ak = array_keys ($events);
	foreach ($events as $key=>$evt) {
		$new_m_id = $evt['meeting_id'];
		if ( ($old_m_id) && ($old_m_id != "NULL") && ($new_m_id > 0) && ($old_m_id == $new_m_id) ) {
			$old_m_id = $new_m_id;
			unset ($events[$key]);
		}
		$events[$key] = pnModAPIFunc('PostCalendar','event','fixEventDetails',$events[$key]);
		$old_m_id = $evt['meeting_id'];
	}
	return $events;
}

/**
 * I believe this function returns an array of events sorted by date
 *
 *
 **/
function postcalendar_eventapi_getEvents($args)
{	
	$s_keywords = $s_category = $s_topic = '';
	extract($args);
	$date = postcalendar_getDate();
	$cy = substr($date,0,4);
	$cm = substr($date,4,2);
	$cd = substr($date,6,2);

	if(isset($start) && isset($end)) {
		// parse start date
		list($sm,$sd,$sy) = explode('/',$start);
		// parse end date
		list($em,$ed,$ey) = explode('/',$end);
	
		$s = (int) "$sy$sm$sd";
		if($s > $date) {
			$cy = $sy;
			$cm = $sm;
			$cd = $sd;
		}
		$start_date = Date_Calc::dateFormat($sd,$sm,$sy,'%Y-%m-%d');
		$end_date = Date_Calc::dateFormat($ed,$em,$ey,'%Y-%m-%d');
	} else {
		$sm = $em = $cm;
		$sd = $ed = $cd;
		$sy = $cy;
		$ey = $cy+2;
		$start_date = $sy.'-'.$sm.'-'.$sd;
		$end_date = $ey.'-'.$em.'-'.$ed;
	}
	if(!isset($events)) {
		if(!isset($s_keywords)) $s_keywords = '';
		$a = array('start'=>$start_date,'end'=>$end_date,'s_keywords'=>$s_keywords,'s_category'=>$s_category,'s_topic'=>$s_topic);
		$events = pnModAPIFunc('PostCalendar','event','queryEvents',$a);
	}

	//==============================================================
	//	Here we build an array consisting of the date ranges
	//	specific to the current view.  This array is then
	//	used to build the calendar display.
	//==============================================================
	$days = array();
	$sday = Date_Calc::dateToDays($sd,$sm,$sy);
	$eday = Date_Calc::dateToDays($ed,$em,$ey);
	for($cday = $sday; $cday <= $eday; $cday++) {
		$d = Date_Calc::daysToDate($cday,'%d');
		$m = Date_Calc::daysToDate($cday,'%m');
		$y = Date_Calc::daysToDate($cday,'%Y');
		$store_date = Date_Calc::dateFormat($d,$m,$y,'%Y-%m-%d');
		$days[$store_date] = array();
	}
	
	//echo "GetEvents Line 729<br>";
	//$users = pnUserGetAll();
	//$nuke_users = array();
	
	//foreach($users as $user) {
	//	  $nuke_users[strtolower($user['uname'])] = $user['uid'];
	//}
	//unset($users);
	
	foreach($events as $event) {
		// get the name of the topic
		$topicname = pcGetTopicName($event['topic']);
		// get the user id of event's author
		//$cuserid = @$nuke_users[strtolower($event['uname'])];
		// CAH mod 4/12/09
		$cuserid = pnUserGetIDFromName(strtolower($event['uname']));

		// check the current event's permissions
		// the user does not have permission to view this event
		// if any of the following evaluate as false
		if(!pnSecAuthAction(0, 'PostCalendar::Event', "$event[title]::$event[eid]", ACCESS_OVERVIEW)) {
			continue;
		} elseif(!pnSecAuthAction(0, 'PostCalendar::Category', "$event[catname]::$event[catid]", ACCESS_OVERVIEW)) {
			continue;
		} elseif(!pnSecAuthAction(0, 'PostCalendar::User', "$event[uname]::$cuserid", ACCESS_OVERVIEW)) {
			continue;
		} elseif(!pnSecAuthAction(0, 'PostCalendar::Topic', "$topicname::$event[topic]", ACCESS_OVERVIEW)) {
			continue;
		}
		// parse the event start date
		list($esY,$esM,$esD) = explode('-',$event['eventDate']);
		// grab the recurring specs for the event
		$event_recurrspec = @unserialize($event['recurrspec']);
		// determine the stop date for this event
		if($event['endDate'] == '0000-00-00') {
			$stop = $end_date; //CAH enddate has no value here (maybe passed as arg?)
		} else {
			$stop = $event['endDate'];
		}

		switch($event['recurrtype']) {
			//==============================================================
			//	Events that do not repeat only have a startday
			//==============================================================
			case NO_REPEAT :
				if(isset($days[$event['eventDate']])) {
					array_push($days[$event['eventDate']],$event); //CAH this line has no meaning. it seems backward and pushes the same value
				}
				break;
			//==============================================================
			//	Find events that repeat at a certain frequency
			//	Every,Every Other,Every Third,Every Fourth
			//	Day,Week,Month,Year,MWF,TR,M-F,SS
			//==============================================================   
			case REPEAT :
				$rfreq = $event_recurrspec['event_repeat_freq'];
				$rtype = $event_recurrspec['event_repeat_freq_type'];
				// we should bring the event up to date to make this a tad bit faster
				// any ideas on how to do that, exactly??? dateToDays probably.
				$nm = $esM; $ny = $esY; $nd = $esD; 
				$occurance = Date_Calc::dateFormat($nd,$nm,$ny,'%Y-%m-%d');
				while($occurance < $start_date) {
					$occurance = __increment($nd,$nm,$ny,$rfreq,$rtype);
					list($ny,$nm,$nd) = explode('-',$occurance);
				}
				while($occurance <= $stop) {
					if(isset($days[$occurance])) { array_push($days[$occurance],$event); }
					$occurance = __increment($nd,$nm,$ny,$rfreq,$rtype);
					list($ny,$nm,$nd) = explode('-',$occurance);
				}
				break;
			//==============================================================
			//	Find events that repeat on certain parameters
			//	On 1st,2nd,3rd,4th,Last
			//	Sun,Mon,Tue,Wed,Thu,Fri,Sat
			//	Every N Months
			//==============================================================	 
			case REPEAT_ON :
				$rfreq = $event_recurrspec['event_repeat_on_freq'];
				$rnum  = $event_recurrspec['event_repeat_on_num'];
				$rday  = $event_recurrspec['event_repeat_on_day'];
				//==============================================================
				//	Populate - Enter data into the event array
				//==============================================================
				$nm = $esM; $ny = $esY; $nd = $esD;
				// make us current
				while($ny < $cy) {
					$occurance = date('Y-m-d',mktime(0,0,0,$nm+$rfreq,$nd,$ny));
					list($ny,$nm,$nd) = explode('-',$occurance);
				}
				// populate the event array
				while($ny <= $cy) {
					$dnum = $rnum; // get day event repeats on
					do {
						$occurance = Date_Calc::NWeekdayOfMonth($dnum--,$rday,$nm,$ny,$format="%Y-%m-%d");
					} while($occurance === -1);
					if(isset($days[$occurance]) && $occurance <= $stop) { array_push($days[$occurance],$event); }
					$occurance = date('Y-m-d',mktime(0,0,0,$nm+$rfreq,$nd,$ny));
					list($ny,$nm,$nd) = explode('-',$occurance);
				}
				break;
		} // <- end of switch($event['recurrtype'])
	} // <- end of foreach($events as $event)
	return $days;
}
/**
 *	postcalendar_eventapi_writeEvent()
 *	write an event to the DB
 *	@param $args array of event data
 *	@return bool true on success : false on failure;
 */
function postcalendar_eventapi_writeEvent($args) 
{
	$event_for_userid = $_POST['event_for_userid']; // gets the value out of the event_for_userid dropdown :: becomes aid?

	extract($args); unset($args);
	list($dbconn) = pnDBGetConn();
	$pntable = pnDBGetTables();

	define('PC_ACCESS_ADMIN', pnSecAuthAction(0, 'PostCalendar::', '::', ACCESS_OVERVIEW));
	
	// determine if the event is to be published immediately or not
	if ( (bool) _SETTING_DIRECT_SUBMIT || (bool) PC_ACCESS_ADMIN || ($event_sharing != SHARING_GLOBAL) ) {
		$event_status = _EVENT_APPROVED;
	} else {
		$event_status = _EVENT_QUEUED;
	}

	// set up some vars for the insert statement
	$startDate = $event_startyear.'-'.$event_startmonth.'-'.$event_startday;
	if ($event_endtype == 1) {
		$endDate = $event_endyear.'-'.$event_endmonth.'-'.$event_endday;
	} else {
		$endDate = '0000-00-00';
	}

	if(!isset($event_allday)) $event_allday = 0;

	if ((bool)_SETTING_TIME_24HOUR) {
		$startTime = $event_starttimeh.':'.$event_starttimem.':00';
	} else { 
		if ($event_startampm == _AM_VAL) {
			$event_starttimeh = $event_starttimeh == 12 ? '00' : $event_starttimeh;
		} else {
			$event_starttimeh = $event_starttimeh != 12 ? $event_starttimeh+=12 : $event_starttimeh;
		}
	}

	$startTime = sprintf('%02d',$event_starttimeh).':'.sprintf('%02d',$event_starttimem).':00';
		
	$event_userid = $event_for_userid;
	
	if (empty($event_desc))	{
		$event_desc .= 'n/a'; // default description
	} else {
		$event_desc = ':'.$pc_html_or_text.':'.$event_desc; // inserts :text:/:html: before actual content
	}
	
	// Start defining pc_meeting_id
	if($_POST['participants']) {
		$participants = $_POST['participants'];
		$pc_meeting_id	= (int) DBUtil::selectFieldMax ('postcalendar_events', 'meeting_id');
		$pc_meeting_id++;
	} else {
		$pc_meeting_id = 0;
	}
	if(!in_array($event_for_userid, $participants))	$participants[] = $event_for_userid;   
	
	if(!isset($is_update)) $is_update = false; 

	if(!$is_update) $pc_event_id = $dbconn->GenId($pntable['postcalendar_events']);

	// build an array of users for mail notification
	$pc_mail_users = array ();
	
	foreach($participants as $part) // V4B SB LOOP to insert events for every participant
	{
		$eventarray[$pc_event_id] = array( 
					'title'				=>pnVarPrepForStore($event_subject),
					'hometext'		=>pnVarPrepForStore($event_desc),
					'topic'				=>pnVarPrepForStore($event_topic),
					'eventDate'		=>pnVarPrepForStore($startDate),
					'endDate'			=>pnVarPrepForStore($endDate),
					'recurrtype'	=>pnVarPrepForStore($event_repeat),
					'startTime'		=>pnVarPrepForStore($startTime),
					'alldayevent'	=>pnVarPrepForStore($event_allday),
					'catid'				=>pnVarPrepForStore($event_category),
					'location'		=>pnVarPrepForStore($event_location_info),
					'conttel'			=>pnVarPrepForStore($event_conttel),
					'contname'		=>pnVarPrepForStore($event_contname),
					'contemail'		=>pnVarPrepForStore($event_contemail),
					'website'			=>pnVarPrepForStore($event_website),
					'fee'					=>pnVarPrepForStore($event_fee),
					'eventstatus'	=>pnVarPrepForStore($event_status),
					'recurrspec'	=>pnVarPrepForStore($event_recurrspec),
					'duration'		=>pnVarPrepForStore($event_duration),
					'sharing'			=>pnVarPrepForStore($event_sharing),
					'aid'					=>pnVarPrepForStore($part));
		if ($is_update) {
			$eventarray[$pc_event_id]['eid']						= pnVarPrepForStore($pc_event_id);
			$result=pnModAPIFunc('postcalendar', 'event', 'update', $eventarray[$pc_event_id]);
		} else {
			$eventarray[$pc_event_id]['time']				= pnVarPrepForStore(date("Y-m-d H:i:s")); //current date
			$eventarray[$pc_event_id]['informant']	= pnVarPrepForStore($uname);
			$eventarray[$pc_event_id]['meeting_id']	= pnVarPrepForStore($pc_meeting_id);

			$result=pnModAPIFunc('postcalendar', 'event', 'create', $eventarray[$pc_event_id]);
		}
		if ($result === false) {
			// post some kind of error message...
			return false;
		}
		
		// v4b TS start - build an array of users for mail notification
		if (( pnUserGetVar('uname', $part) != $uname ) && (!$is_update)) {
			$pc_mail_users[] = $part;
			$pc_mail_events[] = $dbconn->PO_Insert_ID($pntable['postcalendar_events'],'pc_eid');
		}
	}	 // V4B SB Foreach End
	
	if ((bool)$is_update) {
		$eid = $pc_event_id;
	} else {
		$eid = $dbconn->PO_Insert_ID($pntable['postcalendar_events'],'pc_eid');
	}

	pc_notify($eid,$is_update); // this is sending email
	
	// v4b TS start - mail information for participants
	if (!$is_update) 
	{
		// prepare the values for putput
		@list($pc_dur_hours,$dmin) = @explode('.',($event_duration/60/60));
		$pc_dur_minutes = substr(sprintf('%.2f','.' . 60*($dmin/100)),2,2);
		$display_type = substr($event_desc,0,6);

		if($display_type == ':text:') 
			$pc_description = substr($event_desc,6);
		elseif($display_type == ':html:') 
			$pc_description = substr($event_desc,6);

		list($x,$y,$z) = explode('-',$startDate);
		list($a,$b,$c) = explode('-',$startTime);
		$time = mktime($a,$b,$c,$y,$z,$x);
		$pc_start_time = strftime('%H:%M', $time);
		
		for ($i=0; $i < count($pc_mail_users); $i++) {
			// build the url, get the authors name
			$pc_eid = $pc_mail_events[$i];
			$pc_URL = pnModURL ('PostCalendar', 'user', 'view', array('viewtype'=>'details','eid'=>$pc_eid));
			//$pc_author = pnUserGetVar('name', $event_for_userid);
			$pc_author = $uname;
		
			// process mail file to generate mail text
			$currentlang = pnVarPrepForOS (pnUserGetLang());
			$mailfile = "modules/PostCalendar/pnlang/$currentlang/mails/mail_meeting_notification.php";	   

			if (file_exists($mailfile)) {
				$fhandle	 = fopen($mailfile, "r");
				$mailtext	 = fread($fhandle, filesize($mailfile));
				fclose($fhandle);
				$mailtext	 = '$mailtext = "' . $mailtext . '";';
				eval ($mailtext);

				// mail the users
				$extraHeader = "From: noreply"; 
				$email	  = pnUserGetVar('email', $pc_mail_users[$i]);
				$subject  = _PC_MEETING_MAIL_TITLE . ": $event_subject";

				if ($email)	pnMail ($email, $subject, $mailtext, $extraHeader);
			}
		}
	}
	
	// v4b TS end - mail information for participants
	return true;
}

/**
 *	postcalendar_eventapi_buildSubmitForm()
 *	create event submit form
 */
function postcalendar_eventapi_buildSubmitForm($args)
{
	if (!pnSecAuthAction(0, 'PostCalendar::', '::', ACCESS_ADD)) {
		return LogUtil::registerPermissionError();
	}
	//pcDebugVar($args); die;

	extract($args); 
	unset($args);

	if (!$admin) $admin = false; //reset default value

	$tpl = pnRender::getInstance('PostCalendar');
	PostCalendarSmartySetup($tpl);
	$tpl->caching = false;

	// V4B RNG start
	//================================================================
	//		  build the username filter pulldown
	//================================================================
	if(true) // if why?
	{
		$event_for_userid = (int)DBUtil::selectFieldByID ('postcalendar_events', 'aid', $pc_event_id, 'eid');

		$uid   = pnUserGetVar('uid');
		$uname = pnUserGetVar('uname');
		$idsel = ($event_for_userid ? $event_for_userid : $uid);
		$namesel = "";

		@define('_PC_FORM_USERNAME',true);

		//get users that have submitted events previously
		$users = DBUtil::selectFieldArray ('postcalendar_events', 'informant', null, null, true, 'aid');
		if (!array_key_exists($idsel, $users)) { 
			$users[$uid] = $uname; // add current user to userlist if not already there
		}
		$tpl->assign('users', $users);
		$tpl->assign('user_selected', $idsel);
	}

	$endDate = $event_endyear.$event_endmonth.$event_endday;
	$today = postcalendar_getDate();
	if(($endDate == '')||($endDate == '00000000')) {
		$endvalue = substr($today, 6, 2) .'-'. substr($today, 4, 2) .'-'. substr($today, 0, 4);
		// V4B RNG: build other date format for JS cal
		$endDate = substr($today, 0, 4) .'-'. substr($today, 4, 2) . '-' . substr($today, 6, 2);
	} else {
		$endvalue = substr($endDate, 6, 2) .'-'. substr($endDate, 4, 2) .'-'. substr($endDate, 0, 4);
		// V4B RNG: build other date format for JS cal
		$endDate = substr($endDate, 0, 4) .'-'. substr($endDate, 4, 2) . '-' . substr($endDate, 6, 2);
	}
	$tpl->assign('endvalue', $endvalue);	
	$tpl->assign('endDate', $endDate);

	$startdate = $event_startyear.$event_startmonth.$event_startday;
	$today = postcalendar_getDate();
	if($startdate == '') {
		$startvalue = substr($today, 6, 2) .'-'. substr($today, 4, 2) .'-'. substr($today, 0, 4);
		// V4B RNG: build other date format for JS cal
		$startdate = substr($today, 0, 4) .'-'. substr($today, 4, 2) . '-' . substr($today, 6, 2);
	} else {
		$startvalue = substr($startdate, 6, 2) .'-'. substr($startdate, 4, 2) .'-'. substr($startdate, 0, 4);
		// V4B RNG: build other date format for JS cal
		$startdate = substr($startdate, 0, 4) .'-'. substr($startdate, 4, 2) . '-' . substr($startdate, 6, 2);
	}
	$tpl->assign('startvalue', $startvalue);	
	$tpl->assign('startdate', $startdate);
	// V4B SB END // JAVASCRIPT CALENDAR
	//================================================================
	//	build the userlist select box
	//================================================================
	if(true) { // change this to only perform if displaymeetingoptions & useaddressbook?
		$users = DBUtil::selectFieldArray ('users', 'uname', null, null, true, 'uid'); // ALL users... ick.
	}

	//================================================================
	//	build the participants select box
	//================================================================
	if($meeting_id) {  //means a meeting is established (i.e. not 0)
		$participants = array();
		$where	   = 'WHERE pc_meeting_id=' . pnVarPrepForStore ($meeting_id);
		$attendees = DBUtil::selectFieldArray ('postcalendar_events', 'aid', $where);
		foreach($attendees as $uid) {
			$participants[$uid] = $users[$uid];
			unset($users[$uid]);
		}
		$tpl->assign('ParticipantsSelected', $participants);
	}
	$tpl->assign('UserListSelectorOptions', $users);

	$all_categories = pnModAPIFunc('PostCalendar','user','getCategories');

	//=================================================================
	//	PARSE MAIN
	//=================================================================
	$tpl->assign('VIEW_TYPE',					''); // E_ALL Fix
	$tpl->assign('FUNCTION',					FormUtil::getPassedValue('func'));
	$tpl->assign('ModuleName',				$modname);
	$tpl->assign('ModuleDirectory',		$modir);
	$tpl->assign('category',					$all_categories);
		
	//=================================================================
	//	PARSE INPUT_EVENT_TITLE
	//=================================================================
	$tpl->assign('ValueEventTitle',		pnVarPrepForDisplay($event_subject));
	
	//=================================================================
	//	PARSE SELECT_DATE_TIME
	//=================================================================
	$tpl->assign('SelectedAllday',		$event_allday==1 ? 'checked':'');
	$tpl->assign('SelectedTimed',			$event_allday==0 ? 'checked':'');

	//=================================================================
	//	PARSE SELECT_TIMED_EVENT
	//=================================================================
	$tpl->assign('minute_interval', _SETTING_TIME_INCREMENT);
	if (empty($event_starttimeh)) {$event_starttimeh = "01"; $event_starttimem = "00";}
	$tpl->assign('SelectedTime', $event_starttimeh.":".$event_starttimem);
	
	//=================================================================
	//	PARSE SELECT_DURATION
	//=================================================================

	if (!$event_dur_hours) $event_dur_hours = 1;  // provide a reasonable default rather than 0 hours

	for($i=0; $i<=24; $i+=1) 
		$TimedDurationHours[$i] = array('value'=>$i,
						'selected'=>($event_dur_hours==$i ? 'selected':''),
						'name'=>sprintf('%02d',$i));

	$tpl->assign('TimedDurationHours',$TimedDurationHours);
	
	for($i=0; $i<60; $i+=_SETTING_TIME_INCREMENT) 
		$TimedDurationMinutes[$i] = array('value'=>$i,
						  'selected'=>($event_dur_minutes==$i ? 'selected':''),
						  'name'=>sprintf('%02d',$i));

	$tpl->assign('TimedDurationMinutes',$TimedDurationMinutes);
	
	//=================================================================
	//	PARSE INPUT_EVENT_DESC
	//=================================================================
	if(empty($pc_html_or_text)) 
	{
		$display_type = substr($event_desc,0,6);
		if($display_type == ':text:') 
		{
			$pc_html_or_text = 'text';
			$event_desc = substr($event_desc,6);
		} 
		elseif($display_type == ':html:') 
		{
			$pc_html_or_text = 'html';
			$event_desc = substr($event_desc,6);
		} 
		else 
			$pc_html_or_text = 'text';

		unset($display_type);
	}

	$tpl->assign('ValueEventDesc', pnVarPrepForDisplay($event_desc));
		
	$eventHTMLorText = array('text'=>_PC_SUBMIT_TEXT, 'html'=>_PC_SUBMIT_HTML);
	$tpl->assign('EventHTMLorText',$eventHTMLorText);
	$tpl->assign('EventHTMLorTextVal',$pc_html_or_text);

	//=================================================================
	//	PARSE select_event_topic_block
	//=================================================================
	$tpl->assign('displayTopics',_SETTING_DISPLAY_TOPICS);
	if((bool)_SETTING_DISPLAY_TOPICS) {
		$a_topics = pnModAPIFunc('PostCalendar', 'user', 'getTopics');
		$topics = array();
		foreach($a_topics as $topic) {
			array_push($topics,array('value'=>$topic['topicid'],
									 'selected'=>($topic['topicid']==$event_topic ? 'selected':''),
									 'name'=>$topic['topictext']));
		}
		unset($a_topics);
		// only show this if we have topics to show
		if(count($topics) > 0) {
			$tpl->assign('topics',$topics);
		}
	}
	
	//=================================================================
	//	PARSE select_event_type_block
	//=================================================================
	$categories = array();
	foreach($all_categories as $category) 
	{
		$categories[$category['catid']] = $category['catname'];
	}
	if(count($categories) > 0) 
	{
		$tpl->assign('categories',$categories);
	}
	//$event_category is selected category id
	$tpl->assign('event_category',$event_category);

	//=================================================================
	//	PARSE event_sharing_block
	//=================================================================
	$data = array();
	if(_SETTING_ALLOW_USER_CAL) 
	{
		array_push($data,array(SHARING_PRIVATE,_PC_SHARE_PRIVATE));
		array_push($data,array(SHARING_PUBLIC,_PC_SHARE_PUBLIC));
		array_push($data,array(SHARING_BUSY,_PC_SHARE_SHOWBUSY));
	}

	if(pnSecAuthAction(0,'PostCalendar::', '::', ACCESS_ADMIN) || _SETTING_ALLOW_GLOBAL || !_SETTING_ALLOW_USER_CAL) 
	{
			array_push($data,array(SHARING_GLOBAL,_PC_SHARE_GLOBAL));
			array_push($data,array(SHARING_HIDEDESC,_PC_SHARE_HIDEDESC));
	}

	// V4B RNG Start
	if (!isset($event_sharing))
		$event_sharing = 1;
	// V4B RNG End

	$sharing = array();
	foreach($data as $cell) 
			array_push($sharing,array('value'=>$cell[0],
					  'selected'=>((int) $event_sharing == $cell[0] ? 'selected' : ''),
					  'name'=>$cell[1]));

	$tpl->assign('sharing',$sharing);
	//=================================================================
	//	location information
	//=================================================================
	$tpl->assign('ValueLocation',		pnVarPrepForDisplay($event_location));
	$tpl->assign('ValueStreet1',		pnVarPrepForDisplay($event_street1));
	$tpl->assign('ValueStreet2',		pnVarPrepForDisplay($event_street2));
	$tpl->assign('ValueCity',				pnVarPrepForDisplay($event_city));
	$tpl->assign('ValueState',			pnVarPrepForDisplay($event_state));
	$tpl->assign('ValuePostal',			pnVarPrepForDisplay($event_postal));
	//=================================================================
	//	contact information
	//=================================================================
	$tpl->assign('ValueContact',		pnVarPrepForDisplay($event_contname));
	$tpl->assign('ValuePhone',			pnVarPrepForDisplay($event_conttel));
	$tpl->assign('ValueEmail',			pnVarPrepForDisplay($event_contemail));
	$tpl->assign('ValueWebsite',		pnVarPrepForDisplay($event_website));
	$tpl->assign('ValueFee',				pnVarPrepForDisplay($event_fee));
	//=================================================================
	//	Repeating Information
	//=================================================================
	$tpl->assign('SelectedNoRepeat', (int) $event_repeat==0 ? 'checked':'');
	$tpl->assign('SelectedRepeat', (int) $event_repeat==1 ? 'checked':'');
	
	unset($in); 
	$in = array(_PC_EVERY,_PC_EVERY_OTHER,_PC_EVERY_THIRD,_PC_EVERY_FOURTH);
	$keys = array(REPEAT_EVERY,REPEAT_EVERY_OTHER,REPEAT_EVERY_THIRD,REPEAT_EVERY_FOURTH);
	$repeat_freq = array();
	foreach($in as $k=>$v) 
			array_push($repeat_freq,array('value'=>$keys[$k],
						'selected'=>($keys[$k]==$event_repeat_freq?'selected':''),
						'name'=>$v));

	if(empty($event_repeat_freq) || $event_repeat_freq < 1) $event_repeat_freq = 1;
		$tpl->assign('InputRepeatFreqVal',$event_repeat_freq);
	$tpl->assign('repeat_freq',$repeat_freq);
	
	unset($in); 
	$in = array(_PC_EVERY_DAY,_PC_EVERY_WEEK,_PC_EVERY_MONTH,_PC_EVERY_YEAR);
	$keys = array(REPEAT_EVERY_DAY,REPEAT_EVERY_WEEK,REPEAT_EVERY_MONTH,REPEAT_EVERY_YEAR);
	$repeat_freq_type = array();
	foreach($in as $k=>$v) 
		array_push($repeat_freq_type,array('value'=>$keys[$k],
						   'selected'=>($keys[$k]==$event_repeat_freq_type?'selected':''),
						   'name'=>$v));

	$tpl->assign('repeat_freq_type',$repeat_freq_type);
	$tpl->assign('SelectedRepeatOn', (int) $event_repeat==2 ? 'checked':'');
	
	unset($in); 
	$in = array(_PC_EVERY_1ST,_PC_EVERY_2ND,_PC_EVERY_3RD,_PC_EVERY_4TH,_PC_EVERY_LAST);
	$keys = array(REPEAT_ON_1ST,REPEAT_ON_2ND,REPEAT_ON_3RD,REPEAT_ON_4TH,REPEAT_ON_LAST);
	$repeat_on_num = array();
	foreach($in as $k=>$v) 
		array_push($repeat_on_num,array('value'=>$keys[$k],
						'selected'=>($keys[$k]==$event_repeat_on_num?'selected':''),
						'name'=>$v));

	$tpl->assign('repeat_on_num',$repeat_on_num);
	
	unset($in); 
	$in = array(_PC_EVERY_SUN,_PC_EVERY_MON,_PC_EVERY_TUE,_PC_EVERY_WED,_PC_EVERY_THU,_PC_EVERY_FRI,_PC_EVERY_SAT);
	$keys = array(REPEAT_ON_SUN,REPEAT_ON_MON,REPEAT_ON_TUE,REPEAT_ON_WED,REPEAT_ON_THU,REPEAT_ON_FRI,REPEAT_ON_SAT);
	$repeat_on_day = array();
	foreach($in as $k=>$v) 
		array_push($repeat_on_day,array('value'=>$keys[$k],
						'selected'=>($keys[$k]==$event_repeat_on_day ? 'selected' : ''),
						'name'=>$v));

	$tpl->assign('repeat_on_day',$repeat_on_day);
	
	unset($in); 
	$in = array(_PC_OF_EVERY_MONTH,_PC_OF_EVERY_2MONTH,_PC_OF_EVERY_3MONTH,_PC_OF_EVERY_4MONTH,_PC_OF_EVERY_6MONTH,_PC_OF_EVERY_YEAR);
	$keys = array(REPEAT_ON_MONTH,REPEAT_ON_2MONTH,REPEAT_ON_3MONTH,REPEAT_ON_4MONTH,REPEAT_ON_6MONTH,REPEAT_ON_YEAR);
	$repeat_on_freq = array();
	foreach($in as $k=>$v) 
		array_push($repeat_on_freq,array('value'=>$keys[$k],
						 'selected'=>($keys[$k] == $event_repeat_on_freq ? 'selected' : ''),
						 'name'=>$v));

	if(empty($event_repeat_on_freq) || $event_repeat_on_freq < 1) 
		$event_repeat_on_freq = 1;
	$tpl->assign('InputRepeatOnFreqVal', $event_repeat_on_freq);
	$tpl->assign('repeat_on_freq',$repeat_on_freq);
	
	//=================================================================
	//	PARSE INPUT_END_DATE
	//=================================================================
	$tpl->assign('SelectedEndOn', (int) $event_endtype==1 ? 'checked':'');
	//=================================================================
	//	PARSE INPUT_NO_END
	//=================================================================
	$tpl->assign('SelectedNoEnd', (int) $event_endtype==0 ? 'checked':'');
	
	$tpl->assign('is_update', $is_update);
	$tpl->assign('pc_event_id', $pc_event_id);
	if(isset($data_loaded)) 
	{
		$tpl->assign('data_loaded',$data_loaded);
	}
	
	return $tpl->fetch("event/postcalendar_event_submit.html");
}

function postcalendar_eventapi_fixEventDetails($event)
{
	// there has to be a more intelligent way to do this
	@list($event['duration_hours'],$dmin) = @explode('.',($event['duration']/60/60));
	$event['duration_minutes'] = substr(sprintf('%.2f','.' . 60*($dmin/100)),2,2);
	// ---
	
	$suid = pnUserGetVar('uid');
//	$euid = DBUtil::selectFieldByID ('users', 'uid', $event['uname'], 'uname');
	$euid = $event['aid'];
	
	// is this a public event to be shown as busy?
	if($event['sharing'] == SHARING_PRIVATE && $euid != $suid) {
		// they are not supposed to see this
		return false;
	} elseif($event['sharing'] == SHARING_BUSY && $euid != $suid) {
		// make it not display any information
		$event['title']		= _USER_BUSY_TITLE;
		$event['hometext']	= _USER_BUSY_MESSAGE;
		$event['desc']		= _USER_BUSY_MESSAGE;

		$fields = array ('event_location', 'conttel', 'contname', 'contemail', 'website', 'fee', 
						 'event_street1', 'event_street2', 'event_city', 'event_state', 'event_postal');
		foreach ($fields as $field)
			$event[$field] = '';
	} else {
		// FIXME: this entire thing should be a sub-array 
		$location = unserialize($event['location']);
		$event['event_location'] = $location['event_location'];
		$event['event_street1']	 = $location['event_street1'];
		$event['event_street2']	 = $location['event_street2'];
		$event['event_city']	 = $location['event_city'];
		$event['event_state']	 = $location['event_state'];
		$event['event_postal']	 = $location['event_postal'];
		//$event['date']	 = str_replace('-','',$Date);
	}

	return $event;
}

//function postcalendar_userapi_pcGetEventDetails($eid) 
function postcalendar_eventapi_getEventDetails($eid) 
{
	
	if(!isset($eid))
		return false;

		// FIXME !!!
	$joinInfo = array ();
	$joinInfo[] = array (	'join_table'		  =>  'postcalendar_categories',
				'join_field'		  =>  'catname',
				'object_field_name'	  =>  'catname',
				'compare_field_table' =>  'catid',
				'compare_field_join'  =>  'catid');
	$joinInfo[] = array (	'join_table'		  =>  'postcalendar_categories',
				'join_field'		  =>  'catdesc',
				'object_field_name'	  =>  'catdesc',
				'compare_field_table' =>  'catid',
				'compare_field_join'  =>  'catid');
	$joinInfo[] = array (	'join_table'		  =>  'postcalendar_categories',
				'join_field'		  =>  'catcolor',
				'object_field_name'	  =>  'catcolor',
				'compare_field_table' =>  'catid',
				'compare_field_join'  =>  'catid');
	// FIXME!!!!!!
	//$joinInfo[] = array (	  'join_table'			=>	'topics',
				//'join_field'			=>	'topictext',
				//'object_field_name'	=>	'topictext',
				//'compare_field_table' =>	'topicid',
				//'compare_field_join'	=>	'topic');
	$event = DBUtil::selectExpandedObjectByID ('postcalendar_events', $joinInfo, $eid, 'eid');
	//	$event = postcalendar_userapi_pcFixEventDetails ($event);
	$event = pnModAPIFunc('PostCalendar','event','fixEventDetails',$event);
	return $event;
}

/**
 *	postcalendar_userapi_eventDetail
 *	Creates the detailed event display and outputs html.  
 *	Accepts an array of key/value pairs
 *	@param int $eid the id of the event to display
 *	@return string html output 
 *	@access public				 
 */
//function postcalendar_adminapi_eventDetail($args) { return postcalendar_userapi_eventDetail($args,true); }
//function postcalendar_userapi_eventDetail($args,$admin=false)
function postcalendar_eventapi_eventDetail($args)
{
	if (!pnSecAuthAction(0, 'PostCalendar::', '::', ACCESS_READ)) {
		return LogUtil::registerPermissionError();
	}

	$popup = FormUtil::getPassedValue('popup');
	extract($args); 
	unset($args);

	if (!$admin) $admin = false;

	if(!isset($cacheid)) $cacheid = null;
	if(!isset($eid)) return false;
	if(!isset($nopop)) $nopop = false;
	$uid = pnUserGetVar('uid');

	//$tpl = pnRender::getInstance('PostCalendar');
	//PostCalendarSmartySetup($tpl);
		/* Trim as needed */
			$func  = FormUtil::getPassedValue('func');
			$template_view = FormUtil::getPassedValue('tplview');
			if (!$template_view) $template_view = 'month'; 
			$function_out['FUNCTION'] = $func;
			$function_out['TPL_VIEW'] = $template_view;
		/* end */
  
	if($admin) {
		$function_out['template'] = "admin/postcalendar_admin_view_event_details.htm";
		$function_out['Date'] = postcalendar_getDate();
	} else {
		$function_out['template'] = "user/postcalendar_user_view_event_details.html";
	}
	if ($popup == true) $function_out['template'] = "user/postcalendar_user_view_popup.html";
	
	// let's get the DB information
	//$event = postcalendar_userapi_pcGetEventDetails($eid);
	$event = pnModAPIFunc('PostCalendar','event','getEventDetails',$eid);
	// if the above is false, it's a private event for another user
	// we should not diplay this - so we just exit gracefully
	if($event === false) 
		return false; 
	
	// since recurrevents are dynamically calculcated, we need to change the date 
	// to ensure that the correct/current date is being displayed (rather than the 
	// date on which the recurring booking was executed).	
	if ($event['recurrtype'])
	{
		$y = substr ($Date, 0, 4);
		$m = substr ($Date, 4, 2);
		$d = substr ($Date, 6, 2);
		$event['eventDate'] = "$y-$m-$d";
	}
	
	// populate the template
	$display_type = substr($event['hometext'],0,6);
	if($display_type == ':text:') {
		$prepFunction = 'pcVarPrepForDisplay';
		$event['hometext'] = substr($event['hometext'],6);
	} elseif($display_type == ':html:') {
		$prepFunction = 'pcVarPrepHTMLDisplay';
		$event['hometext'] = substr($event['hometext'],6);
	} else {
		$prepFunction = 'pcVarPrepHTMLDisplay';
	}
	
	unset($display_type);
	// prep the vars for output
	$event['title']		= $prepFunction($event['title']); 
	$event['hometext']	= $prepFunction($event['hometext']);
	$event['desc']		= $event['hometext'];
	$event['conttel']	= $prepFunction($event['conttel']);
	$event['contname']	= $prepFunction($event['contname']);
	$event['contemail'] = $prepFunction($event['contemail']);
	$event['website']	= $prepFunction(postcalendar_makeValidURL($event['website']));
	$event['fee']		= $prepFunction($event['fee']);
	$event['location']	= $prepFunction($event['event_location']);
	$event['street1']	= $prepFunction($event['event_street1']);
	$event['street2']	= $prepFunction($event['event_street2']);
	$event['city']		= $prepFunction($event['event_city']);
	$event['state']		= $prepFunction($event['event_state']);
	$event['postal']	= $prepFunction($event['event_postal']);
	$function_out['A_EVENT'] = $event;
	
	if(!empty($event['location']) || !empty($event['street1']) ||
		!empty($event['street2']) || !empty($event['city']) ||
		!empty($event['state']) || !empty($event['postal'])) 
		$function_out['LOCATION_INFO'] = true;
	else 
		$function_out['LOCATION_INFO'] = false;
	
	if(!empty($event['contname']) || !empty($event['contemail']) ||
		!empty($event['conttel']) || !empty($event['website'])) 
		$function_out['CONTACT_INFO'] = true;
	else 
		$function_out['CONTACT_INFO'] = false;
	
	// determine meeting participants
	$participants = array();
	if ($event['meeting_id'])
	{
		$where	   = 'WHERE pc_meeting_id=' . pnVarPrepForStore ($event['meeting_id']);
		$attendees = DBUtil::selectFieldArray ('postcalendar_events', 'aid', $where);
	
		// FIXME: do we need this here? Just to do a lookup? 
		$users = DBUtil::selectObjectArray ('users', '', '', -1, -1, 'uid', null, $ca);

		foreach ($attendees as $uid) {
			$participants[] = $users[$uid]['uname'];
		}

		sort ($participants);
	}
	$function_out['participants'] = $participants;


	//=================================================================
	//	populate the template $ADMIN_OPTIONS
	//=================================================================
	$target='';
	if(_SETTING_OPEN_NEW_WINDOW) 
		$target = 'target="csCalendar"';

	$admin_edit_url = $admin_delete_url = '';
	if (pnSecAuthAction(0, 'PostCalendar::', '::', ACCESS_ADMIN)) 
	{
		$admin_edit_url		= pnModURL('PostCalendar','admin','submit',array('eid'=>$eid));
		//$admin_delete_url	  = pnModURL('PostCalendar','admin','adminevents',array('action'=>_ACTION_DELETE,'eid'=>$eid));
		$admin_delete_url	= pnModURL('PostCalendar','user','delete',array('eid'=>$eid));
		$admin_copy_url		= pnModURL('PostCalendar','admin','submit',array('eid'=>$eid,'form_action'=>'copy'));
	}
	$user_edit_url = $user_delete_url = '';
	
	if(pnUserLoggedIn()) 
		$logged_in_uid = pnUserGetVar('uid');
	else 
		$logged_in_uid = 1;
	
	$can_edit = false;
	if ((pnSecAuthAction(0, 'PostCalendar::', '::', ACCESS_ADD) && $logged_in_uid == $event['aid']) || 
		 pnSecAuthAction(0, 'PostCalendar::', '::', ACCESS_ADMIN))
	{
		$user_edit_url	   = pnModURL('PostCalendar','user','submit',array('eid'=>$eid));
		$user_delete_url   = pnModURL('PostCalendar','user','delete',array('eid'=>$eid));
		// v4b TS start 1 line
		$user_copy_url	   = pnModURL('PostCalendar','user','submit',array('eid'=>$eid,'form_action'=>'copy'));
		$can_edit = true;
	}

	$function_out['ADMIN_TARGET'] = $target;
	$function_out['ADMIN_EDIT'] = $admin_edit_url;
	$function_out['ADMIN_DELETE'] = $admin_delete_url;
	// v4b TS start 2 lines
	$function_out['ADMIN_COPY'] = $admin_copy_url;
	$function_out['USER_COPY'] = $user_copy_url;

	$function_out['USER_TARGET'] = $target;
	$function_out['USER_EDIT'] = $user_edit_url;
	$function_out['USER_DELETE'] = $user_delete_url;
	$function_out['USER_CAN_EDIT'] = $can_edit;

	/*
	if($popup != 1) {	 
		return $function_out;	
	} else {
		// this concept needs to be changed to simply use a different template if using a popup. CAH 5/9/09
		$theme = pnUserGetTheme();
		$function_out['raw1'] = "<html><head></head><body>\n";
	  //$tpl->display("view_event_details.html",$cacheid);
		
		$function_out['raw2'] .= postcalendar_footer();
		// V4B TS start ***	 Hook code for displaying stuff for events in popup
		if ($_GET["type"] != "admin") {
			$hooks = pnModCallHooks('item', 'display', $eid, "index.php?module=PostCalendar&type=user&func=view&viewtype=details&eid=$eid&popup=1");
			$function_out['raw2'] .= $hooks;
		} 
		// V4B TS end ***  End of Hook code
		$function_out['raw2'] .= "\n</body></html>";
		//session_write_close();
		//exit;
		$function_out['displayaspopup'] = true;
		return $function_out;
	}
	*/
	return $function_out;
}

/**
 *	postcalendar_eventapi_create()
 *	This function creates a new event row in the DB
 *	expected args: obj=array([colname]=>[newval],[colname]=>[newval],[colname]=>[newval], etc...)
 *
 */
function postcalendar_eventapi_create($obj) {
	if (!is_array($obj)) return false;
	$res=DBUtil::insertObject ($obj, 'postcalendar_events', 'eid');
	if ($res) { return true; } else { return false; }
}
/**
 *	postcalendar_eventapi_update()
 *	This function updates many events at once with any new values...
 *	expected args: eventarray=array([id]=>array([id]=>[idval],[colname]=>[newval],
 *		[id2]=>array([id2]=>[idval],[colname]=>[newval])
 *
 */
function postcalendar_eventapi_update($eventarray) {
	if (!is_array($eventarray)) return false;
	$res=DBUtil::updateObjectArray ($eventarray, 'postcalendar_events', 'eid');
	if ($res) { return true; } else { return false; }
}
/**
 *	postcalendar_eventapi_deleteevent
 *	This function deletes one event provided the event ID (eid)
 *	expected args: args=array(['eid']=>idval)
 *
 */
function postcalendar_eventapi_deleteevent($args)
{
	return DBUtil::deleteObjectByID('postcalendar_events', $args['eid'], 'eid');
}
/**
 *	postcalendar_eventapi_deleteeventarray
 *	This function deletes several events when provided an array of ids
 *	expected args: args=array([idval]=>val,[idval2]=>val,[idval3]=>val...)
 *	note the vals are not used. just the keys
 *
 */
function postcalendar_eventapi_deleteeventarray($args)
{
	if (!is_array($args)) return false;
	return DBUtil::deleteObjectsFromKeyArray ($args, 'postcalendar_events', 'eid');
}
?>