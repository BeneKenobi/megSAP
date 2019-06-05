<?php

/** 
	@page blast2products
*/

require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../Common/all.php");

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

//parse command line arguments
$parser = new ToolBase("blast2products", "Searchs for pcr-products in a primer pair generated by primerVariant.");
$parser->addInfile("in",  "Input primer1 file.", false);
$parser->addInfile("in2",  "Input primer2 file.", false);
$parser->addOutfile("out",  "Output TXT file.", false);
//optional
$parser->addInt("max_len", "Maximum product length.", true, 4000);
$parser->addInt("max_blast_hits", "Maximum allowed BLAST hits per primer pair.", true, 10000);
$parser->addInt("max_binding_sites", "Maximum allowed binding sites to consider a primer specific.", true, 3000);
extract($parser->parse($argv));

//function to load blast results grouped by chromosomes
function load_blast_results($filename, $label, &$output)
{
	/*
	# Fields: query id, subject id, % identity, alignment length, mismatches, gap opens, q. start, q. end, s. start, s. end, evalue, bit score
	TNNI3d_1R	chr19	100.00	24	0	0	1	24	55668736	55668759	7e-05	48.1
	TNNI3d_1R	chr19	100.00	18	0	0	3	20	2194693	2194710	0.28	36.2
	TNNI3d_1R	chr19	100.00	17	0	0	3	19	5251756	5251772	1.1	34.2
	*/
	
	$hits_all = 0;
	$hits_3prime = 0;
	$file = file($filename);
	foreach($file as $line)
	{
		if (starts_with($line, "#")) continue;
		
		$parts = explode("\t", $line);
		if (count($parts)<11) continue;
		
		list(, $chr, $identity, $length, , , , $q_end, $start, $end) = $parts;
		++$hits_all;
		
		//check if alignment starts on 3' because this is important for the polymerase to bind
		if ($length==$q_end)
		{
			if (!isset($output[$chr]))
			{
				$output[$chr] = array();
			}
			
			$output[$chr][] = array($identity, $length, $start, $end, $label);
			++$hits_3prime;
		}
	}
	
	return array($hits_all, $hits_3prime);
}


//merge two blast results (because additional PCR-products may also occur using the same primer as fwd AND rev)
$blast = array();
list($hits_all_fwd, $hits_3prime_fwd) = load_blast_results($in, "for", $blast);
list($hits_all_rev, $hits_3prime_rev) = load_blast_results($in2, "rev", $blast);

//abort if too many BLAST hits or binding sites
$abort = false;
$out_h = fopen2($out, "w");
if ($hits_all_fwd+$hits_3prime_rev>$max_blast_hits)
{
	fputs($out_h, "Unspecific Primers\n");
	$abort = true;
}
if ($hits_3prime_fwd>$max_binding_sites)
{
	fputs($out_h, "Unspecific Forward Primer\n");
	$abort = true;
}
if ($hits_3prime_rev>$max_binding_sites)
{
	fputs($out_h, "Unspecific Reverse Primer\n");
	$abort = true;
}
if ($abort)
{
	fputs($out_h, "{$hits_all_fwd} BLAST hits for forward primer ({$hits_3prime_fwd} of these bind at 3'prime end)\n");
	fputs($out_h, "{$hits_all_rev} BLAST hits for reverse primer ({$hits_3prime_rev} of these bind at 3'prime end)\n");
	exit();
}

//reduce to possible hits (same chr, length<max_len)
$possible_hits = array();
foreach ($blast as $chr => $hits)
{	
	foreach ($hits as $hit_p1)
	{	
		foreach ($hits as $hit_p2)
		{	
			//don't compare a result with itself
			if ($hit_p1==$hit_p2) continue;
			
			//check length
			$length = ($hit_p2[3]-$hit_p1[3]-1)+$hit_p2[1]+$hit_p1[1];//distance between the primers+primerlength;
			if ($length<$max_len)
			{
				//if blast2's starts is > blast1' start, blast1's end> blast1's start and blast2's start>blast2's end
				//==primers are facing each other with blast1 left from blast2
				//the opposite case (blast2 left from blast1) need not to be covered because $hit_p1 and $hit_p2 both runs completely through
				//$blast, which includes all results from primer1 and primer2
				if ($hit_p2[2]>$hit_p1[2] && $hit_p1[3]>$hit_p1[2]&& $hit_p2[2]>$hit_p2[3])
				{
					$possible_hits[] = array($chr, $hit_p1, $hit_p2, $length);
				}
			}
		}
	}
}

//abort if no PRC product is found
if (count($possible_hits)==0)
{
	fputs($out_h, "NO likely PCR-product by blast2product. Possible bug, please report!");
	exit();
}

//warn in case of multiple products
if (count($possible_hits)>=2)
{
	fputs($out_h, "Multiple possible PCR-results\n");
}

//print results
$counter=1;
foreach ($possible_hits as $possible_hit)
{
	list($chr, $hit_p1, $hit_p2, $length) = $possible_hit;
	fputs($out_h, $counter.") ".$chr.":".$hit_p1[2]."-".$hit_p2[2]." ".$length."\n");
	++$counter;
}

?>