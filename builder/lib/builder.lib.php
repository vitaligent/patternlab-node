<?php

/*!
 * Pattern Lab Builder Class - v0.1
 *
 * Copyright (c) 2013 Dave Olsen, http://dmolsen.com
 * Licensed under the MIT license
 *
 */

class Builder {

	// i was lazy when i started this project & kept (mainly) to two letter vars. sorry.
	protected $mpl;               // mustache pattern loader instance
	protected $mfs;               // mustache file system loader instance
	protected $d;                 // data from data.json files
	protected $sp;                // source patterns dir
	protected $pp;                // public patterns dir
	protected $dp;                // permissions for the public pattern dirs
	protected $fp;                // permissions for the public pattern files
	protected $wf;                // files to be watched to see if they should be moved
	protected $mf;                // where the files should be moved too
	protected $contentSyncPort;   // for populating the websockets template partial
	protected $navSyncPort;       // for populating the websockets template partial
	protected $patternTypes;      // a list of pattern types that match the directory structure
	protected $patternPaths;      // the paths to patterns for use with mustache
	protected $patternTypesRegex; // the simple regex for the pattern types. used in getEntry()
	protected $navItems;          // the items for the nav. includes view all links
	
	/**
	* When initializing the Builder class or the sub-classes make sure the base properties are configured
	* Also, create the config if it doesn't already exist
	*/
	public function __construct() {
		
		// set-up the configuration options for patternlab
		if (!($config = @parse_ini_file(__DIR__."/../../config/config.ini"))) {
			// config.ini didn't exist so attempt to create it using the default file
			if (!@copy(__DIR__."/../../config/config.ini.default", __DIR__."/../../config/config.ini")) {
				print "Please make sure config.ini.default exists before trying to have Pattern Lab build the config.ini file automagically.";
				exit;
			} else {
				$config = parse_ini_file(__DIR__."/../../config/config.ini");
			}
		}
		
		// populate some standard variables out of the config
		foreach ($config as $key => $value) {
			
			// if the variables are array-like make sure the properties are validated/trimmed/lowercased before saving
			if (($key == "wf") || ($key == "mf")) {
				$values = explode(",",$value);
				array_walk($values,'Builder::trim');
				$this->$key = $values;
			} else {
				$this->$key = $value;
			}
		}
		
		// generate patternTypes as well as patternPaths
		$this->gatherPatternPaths();
		
		// get nav items
		$this->gatherNavItems();
		
	}
	
	/**
	* Simply returns a new Mustache instance that uses the Pattern Loader
	*
	* @return {Object}       an instance of the Mustache engine
	*/
	protected function mustachePatternLoaderInstance() {
		$this->mpl = new Mustache_Engine(array(
						"loader" => new Mustache_Loader_PatternLoader(__DIR__.$this->sp,array("patternPaths" => $this->patternPaths)),
						"partials_loader" => new Mustache_Loader_PatternLoader(__DIR__.$this->sp,array("patternPaths" => $this->patternPaths))
		));
	}
	
	/**
	* Simply returns a new Mustache instance that uses the File System Loader
	*
	* @return {Object}       an instance of the Mustache engine
	*/
	protected function mustacheFileSystemLoaderInstance() {
		$this->mfs = new Mustache_Engine(array(
						"loader" => new Mustache_Loader_FilesystemLoader(__DIR__."/../../source/patternlab-files/"),
						"partials_loader" => new Mustache_Loader_FilesystemLoader(__DIR__."/../../source/patternlab-files/partials/")
		));
	}
	
	/**
	* Renders a pattern within the context of spitting out a finished pattern w/ header & footer
	* @param  {String}       the filename of the file to be rendered
	* @param  {Object}       the instance of mustache to be used in the rendering
	*
	* @return {String}       the final rendered pattern including the standard header and footer for a pattern
	*/
	private function renderFile($f) {
		$h  = file_get_contents(__DIR__.$this->sp."../patternlab-files/pattern-header-footer/header.html");
		$rf = $this->renderPattern($f);
		$f  = file_get_contents(__DIR__.$this->sp."../patternlab-files/pattern-header-footer/footer.html");
		return $h."\n".$rf."\n".$f;
	}
	
	/**
	* Renders a given pattern file using Mustache and incorporating the provided data
	* @param  {String}       the filename of the file to be rendered
	* @param  {Object}       the instance of mustache to be used in the rendering
	*
	* @return {String}       the mark-up as rendered by Mustache
	*/
	protected function renderPattern($f) {
		$d = $this->d;
		if (isset($d->patternSpecific) && array_key_exists($f,$d->patternSpecific)) {
			$d = (object) array_merge((array) $d, (array) $d->patternSpecific->$f);
		}
		return $this->mpl->render($f,$d);
	}
	
	/**
	* Initiates a mustache instance, renders out a full pattern file and places it in the public directory
	*
	* @return {String}       the mark-up placed in it's appropriate location in the public directory
	*/
	protected function renderAndMove() {
		
		// make sure $this->mpl is refreshed on each render & move. for some reason the mustache instance dies
		$this->mustachePatternLoaderInstance();
		
		// scan the pattern source directory
		foreach($this->patternPaths as $patternType) {
			
			foreach($patternType as $pattern => $entry) {
				
				$r = $this->renderFile($entry.".mustache");
				
				// if the pattern directory doesn't exist create it
				$entry = str_replace("/","-",$entry);
				if (!is_dir(__DIR__.$this->pp.$entry)) {
					mkdir(__DIR__.$this->pp.$entry);
					//chmod($this->pp.$entry,$this->dp);
					file_put_contents(__DIR__.$this->pp.$entry."/".$entry.".html",$r);
					//chmod($this->pp.$entry."/pattern.html",$this->fp);
				} else {
					file_put_contents(__DIR__.$this->pp.$entry."/".$entry.".html",$r);
				}
				
			}
			
		}
		
	}
	
	/**
	* Render the index page and style guide
	*
	* @return {String}        writes out the index and style guides
	*/
	protected function generateMainPages() {
		
		// make sure $this->mfs is refreshed on each generation of view all. for some reason the mustache instance dies
		$this->mustacheFileSystemLoaderInstance();
		
		// render out the main pages and move them to public
		$this->navItems['contentsyncport'] = $this->contentSyncPort;
		$this->navItems['navsyncport']     = $this->navSyncPort;
		$this->navItems['patternpaths']    = json_encode($this->patternPaths);
		
		// grab the partials into a data object for the style guide
		$sd = $this->gatherPartials();
		
		// render the "view all" pages
		$this->generateViewAllPages();
		
		// render the index page and the style guide
		$r = $this->mfs->render('index',$this->navItems);
		file_put_contents(__DIR__."/../../public/index.html",$r);
		$s = $this->mfs->render('styleguide',$sd);
		file_put_contents(__DIR__."/../../public/styleguide/html/styleguide.html",$s);
		
	}
	
	/**
	* Renders the view all pages
	*
	* @return {String}        writes out each view all page
	*/
	protected function generateViewAllPages() {
		
		// make sure $this->mfs is refreshed on each generation of view all. for some reason the mustache instance dies
		$this->mustacheFileSystemLoaderInstance();
		
		// add view all to each list
		$i = 0; $k = 0;
		foreach ($this->navItems['buckets'] as $bucket) {
			
			// make sure that the navItems index exists. catches issues with pages & templates
			if (isset($bucket["navItems"])) {
				
				foreach ($bucket["navItems"] as $navItem) {
					
					foreach ($navItem["navSubItems"] as $subItem) {
						
						if ($subItem["patternName"] == "View All") {
							
							// get the pattern parts
							$patternType    = $subItem["patternType"];
							$patternSubType = $subItem["patternSubType"];
							
							// get all the rendered partials that match
							$sid = $this->gatherPartialsByMatch($patternType, $patternSubType);
							
							// render the viewall template
							$v = $this->mfs->render('viewall',$sid);
							
							// if the pattern directory doesn't exist create it
							$patternPath = $patternType."-".$patternSubType;
							if (!is_dir(__DIR__.$this->pp.$patternPath)) {
								mkdir(__DIR__.$this->pp.$patternPath);
								//chmod($this->pp.$entry,$this->dp);
								file_put_contents(__DIR__.$this->pp.$patternPath."/index.html",$v);
								//chmod($this->pp.$entry."/pattern.html",$this->fp);
							} else {
								file_put_contents(__DIR__.$this->pp.$patternPath."/index.html",$v);
							}
						
						}
						
					}
					
				}
				
			}
			
			$i++;
			$k = 0;
			
		}
		
	}
	
	/**
	* Gather data from source/data/data.json
	* Throws all the data into the Builder class scoped d var
	*/
	protected function gatherData() {
		
		// gather the data from the main source data.json
		if (file_exists(__DIR__."/../../source/data/data.json")) {
			$this->d = (object) array_merge(array(), (array) json_decode(file_get_contents(__DIR__."/../../source/data/data.json")));
		}
		
		// add list item data, makes 'listItems' a reserved word
		if (file_exists(__DIR__."/../../source/data/listitems.json")) {
			
			$listItems = (array) json_decode(file_get_contents(__DIR__."/../../source/data/listitems.json"));
			$numbers   = array("one","two","three","four","five","six","seven","eight","nine","ten");
			
			$i = 0;
			$k = 1;
			$c = count($listItems)+1;
			
			$this->d->listItems = new stdClass();
			
			while ($k < $c) {
				
				shuffle($listItems);
				$itemsArray = array();
				$this->d->listItems->$numbers[$k-1] = new stdClass();
				
				while ($i < $k) {
					$itemsArray[] = $listItems[$i];
					$i++;
				}
				
				$this->d->listItems->$numbers[$k-1] = $itemsArray;
				
				$i = 0;
				$k++;
				
			}
			
		}
		
		// add the link names for easy reference, makes 'link' a reserved word
		$this->d->link = new stdClass();
		foreach($this->patternPaths as $patternTypeName => $patterns) {
			
			foreach($patterns as $pattern => $entry) {
				$patternName = $patternTypeName."-".$pattern;
				$entry = str_replace("/","-",$entry);
				$this->d->link->$patternName = "/patterns/".$entry."/".$entry.".html";
			}
			
		}
		
		// add pattern specific data so it can override when a pattern (not partial!) is rendered
		// makes 'patternSpecific' a reserved word
		$this->d->patternSpecifc = new stdClass();
		foreach($this->patternTypes as $patternType) {
			
			// $this->d->patternSpecific["pattern-name-that-matches-render.mustache"] = array of data;
			$patternTypeBits  = explode("-",$patternType,2);
			$patternTypeClean = (((int)$patternTypeBits[0] != 0) || ($patternTypeBits[0] == '00')) ? $patternTypeBits[1] : $patternType;
			
			// find pattern data for pattern subtypes*/
			foreach(glob(__DIR__.$this->sp.$patternType."/*/*.json") as $filename) {
				$entry = $this->getEntry($filename,"j");
				if (in_array($entry,$this->patternPaths[$patternTypeClean])) {
					$patternName = $entry.".mustache";
					$this->d->patternSpecific->$patternName = (array) json_decode(file_get_contents(__DIR__."/../../source/patterns/".$entry.".json"));
				}
			}
			
			// find pattern data for pattern types that are flat (e.g. pages & templates)
			foreach(glob(__DIR__.$this->sp.$patternType."/*.json") as $filename) {
				$entry = $this->getEntry($filename,"j");
				if (in_array($entry,$this->patternPaths[$patternTypeClean])) {
					$patternName = $entry.".mustache";
					$this->d->patternSpecific->$patternName = (array) json_decode(file_get_contents(__DIR__."/../../source/patterns/".$entry.".json"));
				}
			}
			
		}
		
	}	
	
	/**
	* Gathers the partials for the nav drop down in Pattern Lab
	*
	* @return {Array}        the nav items organized by type
	*/
	protected function gatherNavItems() {
		
		$b  = array("buckets" => array()); // the array that will contain the items
		$bi = 0;                           // track the number for the bucket array
		$ni = 0;                           // track the number for the nav items array
		
		// iterate through each pattern and add them to the as buckets
		foreach($this->patternTypes as $patternType) {
			
			// get the bits for a bucket and check to see if the first bit is a number
			$bucketBits = explode("-",$patternType,2);
			$bucket = (((int)$bucketBits[0] != 0) || ($bucketBits[0] == '00')) ? str_replace("-"," ",$bucketBits[1]) : str_replace("-"," ",$patternType);
			
			// add a new bucket
			$b["buckets"][$bi] = array("bucketNameLC" => strtolower($bucket),
									   "bucketNameUC" => ucwords($bucket)); 
			
			// see if a pattern has subtypes
			$patternSubTypes = glob(__DIR__.$this->sp.$patternType."/*",GLOB_ONLYDIR);
			if (count($patternSubTypes) == 0) {
				
				// find the patterns and add them to the nav
				foreach(glob(__DIR__.$this->sp.$patternType."/*.mustache") as $pattern) {
					
					$patternPathBits = explode("/",$pattern);
					if ($patternPathBits[count($patternPathBits) - 1][0] != "_") {
						
						// get the bits for a pattern and check to see if the first bit is a number
						$patternClean = substr($pattern,strlen(__DIR__.$this->sp.$patternType."/"));
						$patternClean = str_replace(".mustache","",$patternClean);
						$patternFinal = $this->getPatternName($patternClean);
						
						// add a new pattern
						$b["buckets"][$bi]["patternItems"][] = array("patternPath" => $patternType."-".$patternClean."/".$patternType."-".$patternClean.".html",
																	 "patternName" => ucwords($patternFinal));
						
					}
					
				}
				
			} else {
				
				// iterate over pattern sub-types
				foreach($patternSubTypes as $dir) {
					
					// get the bits for a directory and check to see if the first bit is a number
					$dirClean = substr($dir,strlen(__DIR__.$this->sp.$patternType."/"));
					$dirBits  = explode("-",$dirClean,2);
					$dirFinal = (((int)$dirBits[0] != 0) || ($dirBits[0] == '00')) ? str_replace("-"," ",$dirBits[1]) : str_replace("-"," ",$dirClean);
					
					// add a new section
					$b["buckets"][$bi]["navItems"][$ni] = array("sectionNameLC" => strtolower($dirFinal),
																"sectionNameUC" => ucwords($dirFinal));
					
					// iterate over patterns
					foreach(glob(__DIR__.$this->sp.$patternType."/".$dirClean."/*.mustache") as $pattern) {
						
						$patternPathBits = explode("/",$pattern);
						if ($patternPathBits[count($patternPathBits) - 1][0] != "_") {
							
							// get the bits for a pattern and check to see if the first bit is a number
							$patternClean = substr($pattern,strlen(__DIR__.$this->sp.$patternType."/".$dirClean."/"));
							$patternClean = str_replace(".mustache","",$patternClean);
							$patternFinal = $this->getPatternName($patternClean);
							
							// add a new pattern
							$b["buckets"][$bi]["navItems"][$ni]["navSubItems"][] = array("patternPath" => $patternType."-".$dirClean."-".$patternClean."/".$patternType."-".$dirClean."-".$patternClean.".html",
																						 "patternName" => ucwords($patternFinal));
						
						}
						
					}
				
					// add a view all for the section
					if (($bucket != 'pages') && ($bucket != 'templates') && isset($b["buckets"][$bi]["navItems"][$ni]["navSubItems"])) {
						$subItemsCount = count($b["buckets"][$bi]["navItems"][$ni]["navSubItems"]);
						$b["buckets"][$bi]["navItems"][$ni]["navSubItems"][$subItemsCount] = array("patternPath" => $patternType."-".$dirClean."/index.html", "patternName" => "View All", "patternType" => $patternType, "patternSubType" => $dirClean);
					}
					
					$ni++;
				}
			}
			
			$bi++;
			$ni = 0;
			
		}
		
		$this->navItems = $b;
		
	}
	
	/**
	* Pulls together all of the pattern paths for use with mustache and the simplified partial matching
	*
	* @return {Array}        an array of pattern paths
	*/
	protected function gatherPatternPaths() {
		
		// set-up vars
		$this->patternPaths = array();
		$this->patternTypes = array();
		
		// get the pattern types
		foreach(glob(__DIR__.$this->sp."/*",GLOB_ONLYDIR) as $patternType) {
			$this->patternTypes[] = substr($patternType,strlen(__DIR__.$this->sp)+1);
		}
		
		// set-up the regex for getEntry()
		$this->getPatternTypesRegex();
		
		// find the patterns for the types
		foreach($this->patternTypes as $patternType) {
			$patternTypePaths = array();
			
			// find pattern paths for pattern subtypes
			foreach(glob(__DIR__.$this->sp.$patternType."/*/*.mustache") as $filename) {
				preg_match('/\/([A-z0-9-_]{1,})\.mustache$/',$filename,$matches);
				$patternBits = explode("-",$matches[1],2);
				$pattern = (((int)$patternBits[0] != 0) || ($patternBits[0] == '00')) ? $patternBits[1] : $matches[1]; // if the first bit of a
				if ($pattern[0] != "_") {
					$patternTypePaths[$pattern] = $this->getEntry($filename);
				}
			}
			
			// find pattern paths for pattern types that are flat (e.g. pages & templates)
			foreach(glob(__DIR__.$this->sp.$patternType."/*.mustache") as $filename) {
				preg_match('/\/([A-z0-9-_]{1,})\.mustache$/',$filename,$matches);
				$patternBits = explode("-",$matches[1],2);
				$pattern = (((int)$patternBits[0] != 0) || ($patternBits[0] == '00')) ? $patternBits[1] : $matches[1]; // if the first bit of a
				if ($pattern[0] != "_") {
					$patternTypePaths[$pattern] = $this->getEntry($filename);
				}
			}
			
			$patternTypeBits = explode("-",$patternType,2);
			$patternTypeClean = (((int)$patternTypeBits[0] != 0) || ($patternTypeBits[0] == '00')) ? $patternTypeBits[1] : $patternType;
			$this->patternPaths[$patternTypeClean] = $patternTypePaths;
			
		}
		
	}
	
	/**
	* Renders the patterns in the source directory so they can be used in the default styleguide
	*
	* @return {Array}        an array of rendered partials
	*/
	protected function gatherPartials() {
		
		// make sure $this->mpl is refreshed on each render & move. for some reason the mustache instance dies
		$this->mustachePatternLoaderInstance();
		
		$p = array("partials" => array());
		
		// loop through pattern paths
		foreach($this->patternPaths as $patternType => $patternTypeValues) {
			
			if (($patternType != "pages") && ($patternType != "templates")) {
				
				foreach($patternTypeValues as $pattern => $entry) {
					
					// double-check the file exists
					if (file_exists(__DIR__."/".$this->sp.$entry.".mustache")) {
						
						// create the pattern name & link, render the partial, and stick it all into the pattern array
						$patternParts    = explode("/",$entry);
						$patternName     = $this->getPatternName($patternParts[2]);
						$patternLink     = str_replace("/","-",$entry)."/".str_replace("/","-",$entry).".html";
						$patternPartial  = $this->renderPattern($entry.".mustache");
						$p["partials"][] = array("patternName" => ucwords($patternName), "patternLink" => $patternLink, "patternPartial" => $patternPartial);
						
					}
					
				}
				
			}
			
		}
		
		return $p;
		
	}
	
	/**
	* Renders the patterns that match a given string so they can be used in the view all styleguides
	* @param  {String}       the pattern type for the pattern
	* @param  {String}       the pattern sub-type
	*
	* @return {Array}        an array of rendered partials that match the given path
	*/
	protected function gatherPartialsByMatch($patternType, $patternSubType) {
		
		// make sure $this->mpl is refreshed on each render & move. for some reason the mustache instance dies
		$this->mustachePatternLoaderInstance();
		
		$p = array("partials" => array());
		
		$patternTypeClean = $this->getPatternName($patternType);
		
		// make sure that pages & templates don't get "view all" pages
		if (($patternTypeClean != 'pages') && ($patternTypeClean != 'templates')) {
			
			// get matches based on pattern type and pattern sub-type
			foreach(glob(__DIR__.$this->sp.$patternType."/".$patternSubType."/*.mustache") as $filename) {
				
				// get the directory match of the pattern
				$entry = $this->getEntry($filename);
					
				// because we're globbing we need to check again to see if the pattern should be ignored
				$patternParts = explode("/",$entry);
				if ($patternParts[2][0] != "_") {
					
					// create the pattern name & link, render the partial, and stick it all into the pattern array
					$patternName     = $this->getPatternName($patternParts[2]);
					$patternLink     = str_replace("/","-",$entry)."/".str_replace("/","-",$entry).".html";
					$patternPartial  = $this->renderPattern($entry.".mustache");
					$p["partials"][] = array("patternName" => ucwords($patternName), "patternLink" => $patternLink, "patternPartial" => $patternPartial);
					
				}
				
			}
			
		}
		
		return $p;
		
	}
	
	/**
	* Get the directory for a given pattern by parsing the file path
	* @param  {String}       the filepath for a directory that contained the match
	* @param  {String}       the the type of match for the pattern matching
	*
	* @return {String}       the directory for the pattern
	*/
	protected function getEntry($filepath,$type = "m") {
		$file = ($type == 'm') ? '\.mustache' : '\.json';
		if (preg_match('/\/('.$this->patternTypesRegex.'\/(([A-z0-9-]{1,})\/|)([A-z0-9-]{1,}))'.$file.'$/',$filepath,$matches)) {
			return $matches[1];
		}
	}
	
	/**
	* Get the space-delimited, english-looking name for a given pattern
	* @param  {String}       the pattern based on the filesystem name
	*
	* @return {String}       a lower-cased version of the pattern name
	*/
	protected function getPatternName($pattern) {
		$patternBits  = explode("-",$pattern,2);
		return (((int)$patternBits[0] != 0) || ($patternBits[0] == '00')) ? str_replace("-"," ",$patternBits[1]) : str_replace("-"," ",$pattern);
	}
	
	/**
	* Create a regex based on pattern types
	*
	* @return {String}       the final regex
	*/
	protected function getPatternTypesRegex() {
		
		$i = 0;
		$regex = "(";
		foreach($this->patternTypes as $patternType) {
			$regex .= ($i != 0) ? "|".$patternType : $patternType;
			$i++;
		}
		$regex .= ")";
		
		$this->patternTypesRegex = $regex;
		
	}
	
	/**
	* Write out the time tracking file so the content sync service will work. A holdover
	* from how I put together the original AJAX polling set-up.
	*
	* @return {String}       file containing a timestamp
	*/
	protected function updateChangeTime() {
		
		if (is_dir(__DIR__."/../../public/")) {
			file_put_contents(__DIR__."/../../public/latest-change.txt",time());
		} else {
			print "Either the public directory for Pattern Lab doesn't exist or the builder is in the wrong location. Please fix.";
			exit;
		}
		
	}
	
	/**
	* Copies a watch file from the given source path to the given public path.
	* NOT for patterns. Is defined in config.ini and is for special files
	* @param  {String}       the source watch file
	* @param  {String}       the public watch file
	*
	* @return {String}       copied file
	*
	* BUG: should probably check to see if the destination dir exists
	*/
	protected function moveFile($s,$p) {
		if (file_exists(__DIR__."/../../source/".$s)) {
			copy(__DIR__."/../../source/".$s,__DIR__."/../../public/".$p);
		}
	}
	
	
	/**
	* Print out the data var. For debugging purposes
	*
	* @return {String}       the formatted version of the d object
	*/
	public function printData() {
		print_r($this->d);
	}
	
	/**
	* Trim a given string. Used in the array_walk() function in __construct as a sanity check
	* @param  {String}       an entry from one of the list-based config entries
	*
	* @return {String}       trimmed version of the given $v var
	*/
	public function trim(&$v) {
		$v = trim($v);
	}
	
	/**
	* Lowercase the given string. Used in the array_walk() function in __construct as a sanity check
	* @param  {String}       an entry from one of the list-based config entries
	*
	* @return {String}       lowercased version of the given $v var
	*/
	public function strtolower(&$v) {
		$v = strtolower($v);
	}

}
