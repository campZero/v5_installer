<?php
/*
	Job definition for 'series'
	eFiction upgrade from version 3.5.x
	
	This is a collection of:
	- series
	- series relation tables
	- cache tables and fields
	
	2017-01-28: Better cache creation
	2017-01-27: Update DB queries to be safer
*/

$fw->jobSteps = array(
		"data"					=> "Copy series table into collections",
		"stories"				=> "Series <-> Story relations",
		"cache"					=> "Build cache fields",
	);


function collections_data($job, $step)
{
	$fw = \Base::instance();
	$limit = $fw->get("limit.medium");
	$i = 0;
	
	if ( $step['success'] == 0 )
	{
		$total = $fw->db3->exec("SELECT COUNT(1) as found FROM `{$fw->dbOld}series`;")[0]['found'];
		$fw->db5->exec ( "UPDATE `{$fw->dbNew}process`SET `success` = 1, `total` = :total WHERE `id` = :id ", [ ':total' => $total, ':id' => $step['id'] ] );
	}

	$dataIn = $fw->db3->exec("SELECT
						Ser.seriesid as collid, 
						inS2.seriesid as parent_series, 
						1 as ordered,
						Ser.title,
						Ser.summary,
						Ser.uid,
						Ser.isopen as open,
						COUNT(DISTINCT R.reviewid) as reviews,
						Ser.challenges as contests,
						COUNT(DISTINCT Ch.chapid) as chapters,
						SUM(Ch.wordcount) as words
					FROM `{$fw->dbOld}series`Ser
					LEFT JOIN `{$fw->dbOld}inseries`inS ON ( Ser.seriesid = inS.seriesid AND inS.subseriesid = 0 )
						LEFT JOIN `{$fw->dbOld}chapters`Ch ON ( Ch.sid = inS.sid )
						LEFT JOIN `{$fw->dbOld}reviews`R ON ( R.item = Ser.seriesid AND R.type='SE' )
					LEFT JOIN `{$fw->dbOld}inseries`inS2 ON ( Ser.seriesid = inS2.subseriesid )
				GROUP BY Ser.seriesid
				ORDER BY Ser.seriesid ASC LIMIT {$step['items']},{$limit};");
				
	$tracking = new DB\SQL\Mapper($fw->db5, $fw->get('installerCFG.db5.prefix').'process');
	$tracking->load(['id = ?', $step['id'] ]);

	if ( 0 < $count = sizeof($dataIn) )
	{
		$newdata = new \DB\SQL\Mapper( $fw->db5, $fw['installerCFG.db5.prefix']."collections" );

		foreach($dataIn as $data)
		{
			// eFiction 3 can have weird series-challenge relations (like ",26" or "Array,26")
			/*
			if ( $data['contests']!="" )
			{
				$c_new = [];
				$cc = explode(",",$data['contests']);

				foreach ( $cc as $c )
					if ( is_numeric($c) ) $c_new[] = $c;
					
				$data['contests'] = implode(",",$c_new);
			}
			*/
			$data['summary'] = stripslashes($data['summary']);
			
			$newdata->copyfrom($data);
			$newdata->save();
			$newdata->reset();
			
			$tracking->items++;
		}

		$tracking->save();
	}

	if ( $count == 0 OR $tracking->items>=$tracking->total )
	{
		// There was either nothing to be done, or there are no elements left for the next run
		$tracking->success = 2;
		$tracking->save();
	}
}

function collections_stories($job, $step)
{
	$fw = \Base::instance();

	$dataIn = $fw->db3->exec("SELECT `seriesid`, `sid`, `confirmed`, `inorder` FROM `{$fw->dbOld}inseries` WHERE `subseriesid` = 0 AND `sid` > 0;");

	// build the insert values, only numeric, so bulk-insert
	if ( sizeof($dataIn)>0 )
	{
		foreach($dataIn as $data)
			$values[] = "( '{$data['seriesid']}', '{$data['sid']}', '{$data['confirmed']}', '{$data['inorder']}' )";

		$fw->db5->exec ( "INSERT INTO `{$fw->dbNew}collection_stories` ( `collid`, `sid`, `confirmed`, `inorder` ) VALUES ".implode(", ",$values)."; " );
		$count = $fw->db5->count();
	}
	else $count = 0;
	
	$fw->db5->exec ( "UPDATE `{$fw->dbNew}process`SET `success` = 2, `items` = :items WHERE `id` = :id ", 
						[ 
							':items' => $count,
							':id' => $step['id']
						]
					);
}

function collections_cache($job, $step)
{
	$fw = \Base::instance();
	$limit = $fw->get("limit.xheavy");
	
	if ( $step['success'] == 0 )
	{
		$total = $fw->db5->exec("SELECT COUNT(*) as found FROM `{$fw->dbNew}collections`;")[0]['found'];
		$fw->db5->exec ( "UPDATE `{$fw->dbNew}process`SET `success` = 1, `total` = :total WHERE `id` = :id ", [ ':total' => $total, ':id' => $step['id'] ] );
	}

	$dataIn = $fw->db5->exec("SELECT 
								COLLECTION.collid, 
								COLLECTION.tagblock, 
								COLLECTION.characterblock, 
								COLLECTION.authorblock, 
								COLLECTION.categoryblock, 
								CONCAT(rating,'||',max_rating_id) as max_rating
							FROM
							(
							SELECT 
							Coll.collid,
							MAX(Ra.rid) as max_rating_id,
										GROUP_CONCAT(DISTINCT U.uid,',',U.username ORDER BY username ASC SEPARATOR '||' ) as authorblock,
										GROUP_CONCAT(DISTINCT Chara.charid,',',Chara.charname ORDER BY charname ASC SEPARATOR '||') AS characterblock,
										GROUP_CONCAT(DISTINCT C.cid,',',C.category ORDER BY category ASC SEPARATOR '||' ) as categoryblock,
										GROUP_CONCAT(DISTINCT T.tid,',',T.label,',',TG.description ORDER BY TG.order,TG.tgid,T.label ASC SEPARATOR '||') AS tagblock
									FROM 
									(
										SELECT C1.collid
											FROM `{$fw->dbNew}collections`C1
											WHERE C1.cache_authors IS NULL
											LIMIT 0,{$limit}
									) AS Coll
										LEFT JOIN `{$fw->dbNew}collection_stories`rCS ON ( Coll.collid = rCS.collid )
											LEFT JOIN `{$fw->dbNew}stories`S ON ( rCS.sid = S.sid )
												LEFT JOIN `{$fw->dbNew}ratings`Ra ON ( Ra.rid = S.ratingid )
												LEFT JOIN `{$fw->dbNew}stories_tags`rST ON ( rST.sid = S.sid )
													LEFT JOIN `{$fw->dbNew}tags`T ON ( T.tid = rST.tid AND rST.character = 0 )
														LEFT JOIN `{$fw->dbNew}tag_groups`TG ON ( TG.tgid = T.tgid )
													LEFT JOIN `{$fw->dbNew}characters`Chara ON ( Chara.charid = rST.tid AND rST.character = 1 )
												LEFT JOIN `{$fw->dbNew}stories_categories`rSC ON ( rSC.sid = S.sid )
													LEFT JOIN `{$fw->dbNew}categories`C ON ( rSC.cid = C.cid )
												LEFT JOIN `{$fw->dbNew}stories_authors`rSA ON ( rSA.sid = S.sid )
													LEFT JOIN `{$fw->dbNew}users`U ON ( rSA.aid = U.uid )
									GROUP BY Coll.collid
							) AS COLLECTION
							LEFT JOIN `{$fw->dbNew}ratings`R ON (R.rid = max_rating_id);");

	$tracking = new DB\SQL\Mapper($fw->db5, $fw->get('installerCFG.db5.prefix').'process');
	$tracking->load(['id = ?', $step['id'] ]);

	if ( 0 < $count = sizeof($dataIn) )
	{
		$collectionsMap = new \DB\SQL\Mapper( $fw->db5, $fw['installerCFG.db5.prefix']."collections" );
		foreach ( $dataIn as $item)
		{
			$collectionsMap->load(array("collid=?",$item['collid']));
			$collectionsMap->cache_authors		= json_encode(upgradetools::cleanResult($item['authorblock']));
			$collectionsMap->cache_tags			= json_encode(upgradetools::cleanResult($item['tagblock']));
			$collectionsMap->cache_characters	= json_encode(upgradetools::cleanResult($item['characterblock']));
			$collectionsMap->cache_categories	= json_encode(upgradetools::cleanResult($item['categoryblock']));
			$collectionsMap->max_rating			= json_encode(explode(",",$item['max_rating']));
			$collectionsMap->save();

			$tracking->items++;
		}
		$tracking->save();
	}

	if ( $count == 0 OR $tracking->items>=$tracking->total )
	{
		// There was either nothing to be done, or there are no elements left for the next run
		$tracking->success = 2;
		$tracking->save();
	}
}

?>