<?php
/**
 * DynamicPageList
 * DPL Parse Class
 *
 * @author		IlyaHaykinson, Unendlich, Dangerville, Algorithmix, Theaitetos, Alexia E. Smith
 * @license		GPL
 * @package		DynamicPageList
 *
 **/
namespace DPL;

class Parse {
	/**
	 * Mediawiki Database Object
	 *
	 * @var		object
	 */
	private $DB = null;

	/**
	 * Mediawiki Parser Object
	 *
	 * @var		object
	 */
	private $parser = null;

	/**
	 * \DPL\Parameters Object
	 *
	 * @var		object
	 */
	private $parameters = null;

	/**
	 * \DPL\Logger Object
	 *
	 * @var		object
	 */
	private $logger = null;

	/**
	 * Array of prequoted table names.
	 *
	 * @var		array
	 */
	private $tableNames = [];

	/**
	 * Cache Key for this tag parse.
	 *
	 * @var		string
	 */
	private $cacheKey = null;

	/**
	 * Array of possible URL arguments.
	 *
	 * @var		array
	 */
	private $urlArguments = [
		'DPL_offset',
		'DPL_count',
		'DPL_fromTitle',
		'DPL_findTitle',
		'DPL_toTitle'
	];

	/**
	 * Main Constructor
	 *
	 * @access	public
	 * @return	void
	 */
	public function __construct() {
		global $wgRequest;

		$this->DB			= wfGetDB(DB_SLAVE);
		$this->parameters	= new Parameters();
		$this->logger		= new Logger($this->parameters->getData('debug')['default']);
		$this->tableNames	= Query::getTableNames();
		$this->wgRequest	= $wgRequest;
	}

	/**
	 * The real callback function for converting the input text to wiki text output
	 *
	 * @access	public
	 * @param	string	Raw User Input
	 * @param	object	Mediawiki Parser object.
	 * @param	array	End Reset Booleans
	 * @param	array	End Eliminate Booleans
	 * @param	boolean	[Optional] Call as a parser tag
	 * @return	string	Wiki/HTML Output
	 */
	public function parse($input, \Parser $parser, &$reset, &$eliminate, $isParserTag = true) {
		wfProfileIn(__METHOD__);
		$this->parser = $parser;

		//Check that we are not in an infinite transclusion loop
		if (isset($this->parser->mTemplatePath[$this->parser->mTitle->getPrefixedText()])) {
			$this->logger->addMessage(\DynamicPageListHooks::WARN_TRANSCLUSIONLOOP, $this->parser->mTitle->getPrefixedText());
			return $this->getFullOutput();
		}

		//Check if DPL shall only be executed from protected pages.
		if (Config::getSetting('runFromProtectedPagesOnly') === true && !$this->parser->mTitle->isProtected('edit')) {
			//Ideally we would like to allow using a DPL query if the query istelf is coded on a template page which is protected. Then there would be no need for the article to be protected.  However, how can one find out from which wiki source an extension has been invoked???
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_NOTPROTECTED, $this->parser->mTitle->getPrefixedText());
			return $this->getFullOutput();
		}

		/************************************/
		/* Check for URL Arguments in Input */
		/************************************/
		if (strpos($input, '{%DPL_') >= 0) {
			for ($i = 1; $i <= 5; $i++) {
				$this->urlArguments[] = 'DPL_arg'.$i;
			}
		}
		$input = $this->resolveUrlArguments($input, $this->urlArguments);
		$this->getUrlArgs($this->parser);

		$this->parameters->setParameter('offset', $this->wgRequest->getInt('DPL_offset', $this->parameters->getData('offset')['default']));
		$offset = $this->parameters->getParameter('offset');

		/***************************************/
		/* User Input preparation and parsing. */
		/***************************************/
		$cleanParameters = $this->prepareUserInput($input);
		$cleanParameters = $this->parameters->sortByPriority($cleanParameters);
		$this->parameters->setParameter('includeuncat', false); // to check if pseudo-category of Uncategorized pages is included

		foreach ($cleanParameters as $parameter => $option) {
			foreach ($option as $_option) {
				//Parameter functions return true or false.  The full parameter data will be passed into the Query object later.
				if ($this->parameters->$parameter($_option) === false) {
					//Do not build this into the output just yet.  It will be collected at the end.
					$this->logger->addMessage(\DynamicPageListHooks::WARN_WRONGPARAM, $parameter, $_option);
				}
			}
		}

		/*************************/
		/* Execute and Exit Only */
		/*************************/
		if ($this->parameters->getParameter('execandexit') !== null) {
			//The keyword "geturlargs" is used to return the Url arguments and do nothing else.
			if ($this->parameters->getParameter('execandexit') == 'geturlargs') {
				return;
			}
			//In all other cases we return the value of the argument which may contain parser function calls.
			return $this->parameters->getParameter('execandexit');
		}

		//Construct internal keys for TableRow according to the structure of "include".  This will be needed in the output phase.
		if ($this->parameters->getParameter('seclabels') !== null) {
			$this->parameters->setParameter('tablerow', $this->updateTableRowKeys($this->parameters->getParameter('tablerow'), $this->parameters->getParameter('seclabels')));
		}

		/****************/
		/* Check Errors */
		/****************/
		$errors = $this->doQueryErrorChecks();
		if ($errors === false) {
			//WHAT HAS HAPPENED OH NOOOOOOOOOOOOO.
			return $this->getFullOutput();
		}

		$calcRows = false;
		if (!Config::getSetting('allowUnlimitedResults') && $this->parameters->getParameter('goal') != 'categories' && strpos($this->parameters->getParameter('resultsheader').$this->parameters->getParameter('noresultsheader').$this->parameters->getParameter('resultsfooter'), '%TOTALPAGES%') !== false) {
			$calcRows = true;
		}

		/*********/
		/* Query */
		/*********/
		try {
			$this->query = new Query($this->parameters);
			$result = $this->query->buildAndSelect($calcRows);
		} catch (MWException $e) {
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_SQLBUILDERROR, $e->getMessage());
			return $this->getFullOutput(false);
		}

		/*********************/
		/* Handle No Results */
		/*********************/
		$this->addOutput('{{Extension DPL}}');

		if ($this->DB->numRows($result) <= 0) {
			$replacementVariables = [];
			$replacementVariables['%TOTALPAGES%'] = 0;
			$replacementVariables['%PAGES%'] = 0;
			if ($this->parameters->getParameter('noresultsheader') !== null) {
				$this->setHeader($this->replaceVariables($this->parameters->getParameter('noresultsheader'), $replacementVariables));
			}
			if ($this->parameters->getParameter('noresultsfooter') !== null) {
				$this->setFooter($this->replaceVariables($this->parameters->getParameter('noresultsfooter'), $replacementVariables));
			}
			$this->DB->freeResult($result);
			return $this->getFullOutput(false);
		}

		$articles = $this->processQueryResults($result);

		$foundRows = null;
		if ($calcRows) {
			$foundRows = $this->query->getFoundRows();
		}

		//Backward scrolling: If the user specified only titlelt with descending reverse the output order.
		if ($this->parameters->getParameter('titlelt') && !$this->parameters->getParameter('titlegt') && $this->parameters->getParameter('order') == 'descending') {
			$articles = array_reverse($articles);
		}

		//Special sort for card suits (Bridge)
		if ($this->parameters->getParameter('ordersuitsymbols')) {
			$articles = $this->cardSuitSort($articles);
		}

		/*******************/
		/* Generate Output */
		/*******************/
		$listMode = new ListMode(
			$this->parameters->getParameter('mode'),
			$this->parameters->getParameter('secseparators'),
			$this->parameters->getParameter('multisecseparators'),
			$this->parameters->getParameter('inlinetext'),
			$this->parameters->getParameter('listattr'),
			$this->parameters->getParameter('itemattr'),
			$this->parameters->getParameter('listseparators'),
			$offset,
			$this->parameters->getParameter('dominantsection')
		);

		$hListMode = new ListMode(
			$this->parameters->getParameter('headingmode'),
			$this->parameters->getParameter('secseparators'),
			$this->parameters->getParameter('multisecseparators'),
			'',
			$this->parameters->getParameter('hlistattr'),
			$this->parameters->getParameter('hitemattr'),
			$this->parameters->getParameter('listseparators'),
			$offset,
			$this->parameters->getParameter('dominantsection')
		);

		$dpl = new DynamicPageList(
			$headings,
			$this->parameters->getParameter('headingcount'),
			$this->parameters->getParameter('columns'),
			$this->parameters->getParameter('rows'),
			$this->parameters->getParameter('rowsize'),
			$this->parameters->getParameter('rowcolformat'),
			$articles,
			$this->parameters->getParameter('ordermethods')[0],
			$hListMode,
			$listMode,
			$this->parameters->getParameter('escapelinks'),
			$this->parameters->getParameter('addexternallink'),
			$this->parameters->getParameter('incpage'),
			$this->parameters->getParameter('includemaxlen'),
			$this->parameters->getParameter('seclabels'),
			$this->parameters->getParameter('seclabelsmatch'),
			$this->parameters->getParameter('seclabelsnotmatch'),
			$this->parameters->getParameter('incparsed'),
			$this->parser,
			$logger,
			$this->parameters->getParameter('replaceintitle'),
			$this->parameters->getParameter('titlemaxlen'),
			$this->parameters->getParameter('defaulttemplatesuffix'),
			$this->parameters->getParameter('tablerow'),
			$this->parameters->getParameter('includetrim'),
			$this->parameters->getParameter('tablesortcol'),
			$this->parameters->getParameter('updaterules'),
			$this->parameters->getParameter('deleterules')
		);

		if ($foundRows === null) {
			$foundRows = intval($dpl->getRowCount());
		}
		$this->addOutput($dpl->getText());

		/*******************************/
		/* Start Headers/Footers       */
		/*******************************/
		$replacementVariables = [];
		$replacementVariables['%TOTALPAGES%'] = $foundRows;
		$replacementVariables['%VERSION%'] = DPL_VERSION;

		$_headerType = 'results';
		if ($foundRows === 1) {
			$replacementVariables['%PAGES%'] = 1;
			$_headerType = 'oneresult';
		} elseif ($foundRows === 0) {
			$replacementVariables['%PAGES%'] = intval($dpl->getRowCount());
			$_headerType = 'noresults';
		}
		//Only override header and footers if specified.
		if ($this->parameters->getParameter($_headerType.'header') !== null) {
			$header = $this->parameters->getParameter($_headerType.'header');
		}
		if ($this->parameters->getParameter($_headerType.'footer') !== null) {
			$footer = $this->parameters->getParameter($_headerType.'footer');
		}

		// replace %DPLTIME% by execution time and timestamp in header and footer
		$nowTimeStamp   = date('Y/m/d H:i:s');
		$dplElapsedTime = sprintf('%.3f sec.', microtime(true) - $dplStartTime);
		$replacementVariables['%DPLTIME%'] = "{$dplElapsedTime} ({$nowTimeStamp})";

		// replace %LASTTITLE% / %LASTNAMESPACE% by the last title found in header and footer
		if (($n = count($articles)) > 0) {
			$firstNamespaceFound = str_replace(' ', '_', $articles[0]->mTitle->getNamespace());
			$firstTitleFound     = str_replace(' ', '_', $articles[0]->mTitle->getText());
			$lastNamespaceFound  = str_replace(' ', '_', $articles[$n - 1]->mTitle->getNamespace());
			$lastTitleFound      = str_replace(' ', '_', $articles[$n - 1]->mTitle->getText());
		}
		$replacementVariables['%FIRSTNAMESPACE%'] = $firstNamespaceFound;
		$replacementVariables['%FIRSTTITLE%'] = $firstTitleFound;
		$replacementVariables['%LASTNAMESPACE%'] = $lastNamespaceFound;
		$replacementVariables['%LASTTITLE%'] = $lastTitleFound;
		$replacementVariables['%SCROLLDIR%'] = $scrollDir;


		$this->setHeader($this->replaceVariables($header, $replacementVariables));
		$this->setFooter($this->replaceVariables($footer, $replacementVariables));

		if (\DynamicPageListHooks::getDebugLevel() == 5) {
			$this->setHeader('<pre><nowiki>'.$this->getHeader());
			$this->setFooter($this->setFooter().'</nowiki></pre>');
		}

		$scrollVariables = [
			'DPL_firstNamespace'	=> $firstNamespaceFound,
			'DPL_firstTitle'		=> $firstTitleFound,
			'DPL_lastNamespace'		=> $lastNamespaceFound,
			'DPL_lastTitle'			=> $lastTitleFound,
			'DPL_scrollDir'			=> $scrollDir,
			'DPL_time'				=> $replacementVariables['%DPLTIME%'],
			'DPL_count'				=> $this->parameters->getParameter('count'),
			'DPL_totalPages'		=> $foundRows,
			'DPL_pages'				=> $dpl->getRowCount()
		];
		$this->defineScrollVariables($scrollVariables);

		if ($this->parameters->getParameter('allowcachedresults')) {
			$this->parser->getOutput()->updateCacheExpiry($this->parameters->getParameter('cacheperiod') ? $this->parameters->getParameter('cacheperiod') : 3600);
		} else {
			$this->parser->disableCache();
		}

		$this->triggerEndResets($reset, $eliminate, $isParserTag);

		wfProfileOut(__METHOD__);

		return $this->getFullOutput();
	}

	/**
	 * Process Query Results
	 *
	 * @access	private
	 * @param	object	Mediawiki Result Object
	 * @return	array	Array of Article objects.
	 */
	private function processQueryResults($result) {
		/*******************************/
		/* Random Count Pick Generator */
		/*******************************/
		$randomCount = $this->parameters->getParameter('randomcount');
		if ($randomCount > 0) {
			$nResults = $this->DB->numRows($result);
			//mt_srand() seeding was removed due to PHP 5.2.1 and above no longer generating the same sequence for the same seed.
			//Constrain the total amount of random results to not be greater than the total results.
			if ($randomCount > $nResults) {
				$randomCount = $nResults;
			}

			//This is 50% to 150% faster than the old while (true) version that could keep rechecking the same random key over and over again.
			//Generate pick numbers for results.
			$pick = range(1, $nResults);
			//Shuffle the pick numbers.
			shuffle($pick);
			//Select pick numbers from the beginning to the maximum of $randomCount.
			$pick = array_slice($pick, 0, $randomCount);
		}

		//@TODO: Broken headings due to class context.
		$headings = []; //Maps heading to count (# of pages under each heading)
		$articles = [];

		/**********************/
		/* Article Processing */
		/**********************/
		while ($row = $result->fetchRow()) {
			$i++;

			//In random mode skip articles which were not chosen.
			if ($randomCount > 0 && !in_array($i, $pick)) {
				continue;
			}

			if ($this->parameters->getParameter('goal') == 'categories') {
				$pageNamespace = NS_CATEGORY;
				$pageTitle     = $row['cl_to'];
			} elseif ($this->parameters->getParameter('openreferences')) {
				if (count($this->parameters->getParameter('imagecontainer')) > 0) {
					$pageNamespace = NS_FILE;
					$pageTitle     = $row['il_to'];
				} else {
					//Maybe non-existing title
					$pageNamespace = $row['pl_namespace'];
					$pageTitle     = $row['pl_title'];
				}
			} else {
				//Existing PAGE TITLE
				$pageNamespace = $row['page_namespace'];
				$pageTitle     = $row['page_title'];
			}

			// if subpages are to be excluded: skip them
			if (!$this->parameters->getParameter('includesubpages') && strpos($pageTitle, '/') !== false) {
				continue;
			}

			$title     = \Title::makeTitle($pageNamespace, $pageTitle);
			$thisTitle = $this->parser->getTitle();

			//Block recursion from happening by seeing if this result row is the page the DPL query was ran from.
			if ($this->parameters->getParameter('skipthispage') && $thisTitle->equals($title)) {
				continue;
			}

			$articles[] = Article::newFromRow($row, $this->parameters, $title, $pageNamespace, $pageTitle);
		}
		$this->DB->freeResult($result);

		return $articles;
	}

	/**
	 * Do basic clean up and structuring of raw user input.
	 *
	 * @access	private
	 * @param	string	Raw User Input
	 * @return	array	Array of raw text parameter => option.
	 */
	private function prepareUserInput($input) {
		//We replace double angle brackets with single angle brackets to avoid premature tag expansion in the input.
		//The ¦ symbol is an alias for |.
		//The combination '²{' and '}²'will be translated to double curly braces; this allows postponed template execution which is crucial for DPL queries which call other DPL queries.
		$input = str_replace(['«', '»', '¦', '²{', '}²'], ['<', '>', '|', '{{', '}}'], $input);

		//Standard new lines into the standard \n and clean up any hanging new lines.
		$input = str_replace(["\r\n", "\r"], "\n", $input);
		$input = trim($input, "\n");
		$rawParameters = explode("\n", $input);

		foreach ($rawParameters as $key => $parameterOption) {
			if (strpos($parameterOption, '=') === false) {
				$this->logger->addMessage(\DynamicPageListHooks::WARN_PARAMNOOPTION, $parameterOption);
				continue;
			}

			list($parameter, $option) = explode('=', $parameterOption, 2);
			$parameter = trim($parameter);
			$option  = trim($option);

			if (strpos($parameter, '<') !== false || strpos($parameter, '>') !== false) {
				//Having the actual less than and greater than symbols is nasty for programatic look up.  The old parameter is still supported along with the new, but we just fix it here before calling it.
				$parameter = str_replace('<', 'lt', $parameter);
				$parameter = str_replace('>', 'gt', $parameter);
			}

			if (empty($parameter) || substr($parameter, 0, 1) == '#' || ($this->parameters->exists($parameter) && !$this->parameters->testRichness($parameter))) {
				continue;
			}

			if (!$this->parameters->exists($parameter)) {
				$this->logger->addMessage(\DynamicPageListHooks::WARN_UNKNOWNPARAM, $parameter, implode(', ', $this->parameters->getParametersForRichness()));
				continue;
			}

			//Ignore parameter settings without argument (except namespace and category).
			if (empty($option)) {
				if ($parameter != 'namespace' && $parameter != 'notnamespace' && $parameter != 'category' && $this->parameters->exists($parameter)) {
					continue;
				}
			}
			$parameters[$parameter][] = $option;
		}
		return $parameters;
	}

	/**
	 * Concatenate output
	 *
	 * @access	private
	 * @param	string	Output to add
	 * @return	void
	 */
	private function addOutput($output) {
		$this->output .= $output;
	}

	/**
	 * Return output including header and footer.
	 *
	 * @access	public
	 * @param	boolean	[Optional] Are there results in this output?
	 * @return	string	Output
	 */
	private function getFullOutput($results = true) {
		if ($results === false && !$this->getHeader() && !$this->getFooter()) {
			$this->logger->addMessage(\DynamicPageListHooks::WARN_NORESULTS);
		}
		$messages = $this->logger->getMessages();
		if (count($messages)) {
			$messageOutput = implode("<br/>\n", $messages);
		}
		return $messageOutput.$this->header.$this->output.$this->footer;
	}

	/**
	 * Set the header text.
	 *
	 * @access	private
	 * @param	string	Header Text
	 * @return	void
	 */
	private function setHeader($header) {
		$this->header = $header;
	}

	/**
	 * Set the header text.
	 *
	 * @access	private
	 * @return	string	Header Text
	 */
	private function getHeader() {
		return $this->header;
	}

	/**
	 * Set the footer text.
	 *
	 * @access	private
	 * @param	string	Footer Text
	 * @return	void
	 */
	private function setFooter($footer) {
		$this->footer = $footer;
	}

	/**
	 * Set the footer text.
	 *
	 * @access	private
	 * @return	string	Footer Text
	 */
	private function getFooter() {
		return $this->footer;
	}

	/**
	 * Return text with custom new line characters replaced.
	 *
	 * @access	private
	 * @param	string	Text
	 * @return	string	New Lined Text
	 */
	static public function replaceNewLines($text) {
		return str_replace(['\n', "¶"], "\n", $text);
	}

	/**
	 * Return text with variables replaced.
	 *
	 * @access	private
	 * @param	string	Text
	 * @param	array	Array of '%VARIABLE' => 'Replacement' replacements.
	 * @return	string	Replaced Text
	 */
	private function replaceVariables($text, $replacements) {
		$text = self::replaceNewLines($text);
		foreach ($replacements as $variable => $replacement) {
			$text = str_replace($variable, $replacement, $text);
		}
		return $text;
	}

	/**
	 * Work through processed parameters and check for potential issues.
	 *
	 * @access	private
	 * @return	void
	 */
	private function doQueryErrorChecks() {
		/**************************/
		/* Parameter Error Checks */
		/**************************/

		if (is_array($this->parameters->getParameter('category'))) {
			foreach ($this->parameters->getParameter('category') as $comparisonType => $operatorTypes) {
				foreach ($operatorTypes as $operatorType => $categories) {
					$totalCategories += count($categories);
				}
			}
		}
		if (is_array($this->parameters->getParameter('notcategory'))) {
			foreach ($this->parameters->getParameter('notcategory') as $comparisonType => $operatorTypes) {
				foreach ($operatorTypes as $operatorType => $categories) {
					$totalCategories += count($categories);
				}
			}
		}

		//Too many categories.
		if ($totalCategories > Config::getSetting('maxCategoryCount') && !Config::getSetting('allowUnlimitedCategories')) {
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_TOOMANYCATS, Config::getSetting('maxCategoryCount'));
			return false;
		}

		//Not enough categories.(Really?)
		if ($totalCategories < Config::getSetting('minCategoryCount')) {
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_TOOFEWCATS, Config::getSetting('minCategoryCount'));
			return false;
		}

		//Selection criteria needs to be found.
		if (!$totalCategories && !$this->parameters->isSelectionCriteriaFound()) {
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_NOSELECTION);
			return false;
		}

		//ordermethod=sortkey requires ordermethod=category
		//Delayed to the construction of the SQL query, see near line 2211, gs
		//if (in_array('sortkey',$aOrderMethods) && ! in_array('category',$aOrderMethods)) $aOrderMethods[] = 'category';

		$orderMethods = (array) $this->parameters->getParameter('ordermethod');
		//Throw an error in no categories were selected when using category sorting modes or requesting category information.
		if (!$totalCategories == 0 && (in_array('categoryadd', $orderMethods) || $this->parameters->getParameter('addfirstcategorydate') === true)) {
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_CATDATEBUTNOINCLUDEDCATS);
			return false;
		}

		//No more than one type of date at a time!
		//@TODO: Can this be fixed to allow all three later after fixing the article class?
		if ((intval($this->parameters->getParameter('addpagetoucheddate')) + intval($this->parameters->getParameter('addfirstcategorydate')) + intval($this->parameters->getParameter('addeditdate'))) > 1) {
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_MORETHAN1TYPEOFDATE);
			return false;
		}

		// the dominant section must be one of the sections mentioned in includepage
		if ($this->parameters->getParameter('dominantsection') > 0 && count($this->parameters->getParameter('seclabels')) < $this->parameters->getParameter('dominantsection')) {
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_DOMINANTSECTIONRANGE, count($this->parameters->getParameter('seclabels')));
			return false;
		}

		// category-style output requested with not compatible order method
		if ($this->parameters->getParameter('mode') == 'category' && !array_intersect($orderMethods, ['sortkey', 'title', 'titlewithoutnamespace'])) {
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_WRONGORDERMETHOD, 'mode=category', 'sortkey | title | titlewithoutnamespace');
			return false;
		}

		// addpagetoucheddate=true with unappropriate order methods
		if ($this->parameters->getParameter('addpagetoucheddate') && !array_intersect($orderMethods, ['pagetouched', 'title'])) {
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_WRONGORDERMETHOD, 'addpagetoucheddate=true', 'pagetouched | title');
			return false;
		}

		// addeditdate=true but not (ordermethod=...,firstedit or ordermethod=...,lastedit)
		//firstedit (resp. lastedit) -> add date of first (resp. last) revision
		if ($this->parameters->getParameter('addeditdate') && !array_intersect($orderMethods, ['firstedit', 'lastedit']) && ($this->parameters->getParameter('allrevisionsbefore') || $this->parameters->getParameter('allrevisionssince') || $this->parameters->getParameter('firstrevisionsince') || $this->parameters->getParameter('lastrevisionbefore'))) {
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_WRONGORDERMETHOD, 'addeditdate=true', 'firstedit | lastedit');
			return false;
		}

		// adduser=true but not (ordermethod=...,firstedit or ordermethod=...,lastedit)
		/**
		 * @todo allow to add user for other order methods.
		 * The fact is a page may be edited by multiple users. Which user(s) should we show? all? the first or the last one?
		 * Ideally, we could use values such as 'all', 'first' or 'last' for the adduser parameter.
		 */
		if ($this->parameters->getParameter('adduser') && !array_intersect($orderMethods, ['firstedit', 'lastedit']) && !$this->parameters->getParameter('allrevisionsbefore') && !$this->parameters->getParameter('allrevisionssince') && !$this->parameters->getParameter('firstrevisionsince') && !$this->parameters->getParameter('lastrevisionbefore')) {
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_WRONGORDERMETHOD, 'adduser=true', 'firstedit | lastedit');
			return false;
		}
		if ($this->parameters->getParameter('minoredits') && !array_intersect($orderMethods, ['firstedit', 'lastedit'])) {
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_WRONGORDERMETHOD, 'minoredits', 'firstedit | lastedit');
			return false;
		}

		/**
		 * If including the Uncategorized, we need the 'dpl_clview': VIEW of the categorylinks table where we have cl_to='' (empty string) for all uncategorized pages. This VIEW must have been created by the administrator of the mediawiki DB at installation. See the documentation.
		 */
		if ($this->parameters->getParameter('includeuncat')) {
			//If the view is not there, we can't perform logical operations on the Uncategorized.
			if (!$this->DB->tableExists('dpl_clview')) {
				$sql = 'CREATE VIEW '.$this->tableNames['dpl_clview']." AS SELECT IFNULL(cl_from, page_id) AS cl_from, IFNULL(cl_to, '') AS cl_to, cl_sortkey FROM ".$this->tableNames['page'].' LEFT OUTER JOIN '.$this->tableNames['categorylinks'].' ON '.$this->tableNames['page'].'.page_id=cl_from';
				$this->logger->addMessage(\DynamicPageListHooks::FATAL_NOCLVIEW, $this->tableNames['dpl_clview'], $sql);
				return false;
			}
		}

		//add*** parameters have no effect with 'mode=category' (only namespace/title can be viewed in this mode)
		if ($this->parameters->getParameter('mode') == 'category' && ($this->parameters->getParameter('addcategories') || $this->parameters->getParameter('addeditdate') || $this->parameters->getParameter('addfirstcategorydate') || $this->parameters->getParameter('addpagetoucheddate') || $this->parameters->getParameter('incpage') || $this->parameters->getParameter('adduser') || $this->parameters->getParameter('addauthor') || $this->parameters->getParameter('addcontribution') || $this->parameters->getParameter('addlasteditor'))) {
			$this->logger->addMessage(\DynamicPageListHooks::WARN_CATOUTPUTBUTWRONGPARAMS);
		}

		//headingmode has effects with ordermethod on multiple components only
		if ($this->parameters->getParameter('headingmode') != 'none' && count($orderMethods) < 2) {
			$this->logger->addMessage(\DynamicPageListHooks::WARN_HEADINGBUTSIMPLEORDERMETHOD, $this->parameters->getParameter('headingmode'), 'none');
			$this->parameters->setParameter('headingmode', 'none');
		}

		//The 'openreferences' parameter is incompatible with many other options.
		if ($this->parameters->isOpenReferencesConflict() && $this->parameters->getParameter('openreferences') === true) {
			$this->logger->addMessage(\DynamicPageListHooks::FATAL_OPENREFERENCES);
			return false;
		}
		return true;
	}

	/**
	 * Create keys for TableRow which represent the structure of the "include=" arguments.
	 *
	 * @access	public
	 * @param	array	Array of 'tablerow' parameter data.
	 * @param	array	Array of 'include' parameter data.
	 * @return	array	Updated 'tablerow' parameter.
	 */
	private static function updateTableRowKeys($tableRow, $sectionLabels) {
		$_tableRow	= (array) $tableRow;
		$tableRow	= [];
		$groupNr	= -1;
		$t			= -1;
		foreach ($sectionLabels as $label) {
			$t++;
			$groupNr++;
			$cols = explode('}:', $label);
			if (count($cols) <= 1) {
				if (array_key_exists($t, $_tableRow)) {
					$tableRow[$groupNr] = $_tableRow[$t];
				}
			} else {
				$n     = count(explode(':', $cols[1]));
				$colNr = -1;
				$t--;
				for ($i = 1; $i <= $n; $i++) {
					$colNr++;
					$t++;
					if (array_key_exists($t, $_tableRow)) {
						$tableRow[$groupNr.'.'.$colNr] = $_tableRow[$t];
					}
				}
			}
		}
		return $tableRow;
	}

	/**
	 * Resolve arguments in the input that would normally be in the URL.
	 *
	 * @access	public
	 * @param	string	Raw Uncleaned User Input
	 * @param	array	Array of URL arguments to resolve.  Non-arrays will be casted to an array.
	 * @return	string	Raw input with variables replaced
	 */
	private function resolveUrlArguments($input, $arguments) {
		$arguments = (array) $arguments;
		foreach ($arguments as $arg) {
			$dplArg = $this->wgRequest->getVal($arg, '');
			if ($dplArg == '') {
				$input = preg_replace('/\{%' . $arg . ':(.*)%\}/U', '\1', $input);
				$input = str_replace('{%' . $arg . '%}', '', $input);
			} else {
				$input = preg_replace('/\{%' . $arg . ':.*%\}/U  ', $dplArg, $input);
				$input = str_replace('{%' . $arg . '%}', $dplArg, $input);
			}
		}
		return $input;
	}

	/**
	 * This function uses the Variables extension to provide URL-arguments like &DPL_xyz=abc in the form of a variable which can be accessed as {{#var:xyz}} if Extension:Variables is installed.
	 *
	 * @access	public
	 * @return	void
	 */
	private function getUrlArgs() {
		$args = $this->wgRequest->getValues();
		foreach ($args as $argName => $argValue) {
			if (strpos($argName, 'DPL_') === false) {
				continue;
			}
			Variables::setVar(['', '', $argName, $argValue]);
			if (defined('ExtVariables::VERSION')) {
				\ExtVariables::get($this->parser)->setVarValue($argName, $argValue);
			}
		}
	}

	/**
	 * This function uses the Variables extension to provide navigation aids such as DPL_firstTitle, DPL_lastTitle, or DPL_findTitle.  These variables can be accessed as {{#var:DPL_firstTitle}} if Extension:Variables is installed.
	 *
	 * @access	public
	 * @param	array	Array of scroll variables with the key as the variable name and the value as the value.  Non-arrays will be casted to arrays.
	 * @return	void
	 */
	private function defineScrollVariables($scrollVariables) {
		$scrollVariables = (array) $scrollVariables;

		foreach ($scrollVariables as $variable => $value) {
			Variables::setVar(['', '', $variable, $value]);
			if (defined('ExtVariables::VERSION')) {
				\ExtVariables::get($this->parser)->setVarValue($variable, $value);
			}
		}
	}

	/**
	 * Trigger Resets and Eliminates that run at the end of parsing.
	 *
	 * @access	private
	 * @param	array	End Reset Booleans
	 * @param	array	End Eliminate Booleans
	 * @param	boolean	Call as a parser tag
	 * @return	void
	 */
	private function triggerEndResets(&$reset, &$eliminate, $isParserTag) {
		global $wgHooks;

		$localParser = new \Parser();
		$parserOutput = $localParser->parse($this->getFullOutput, $this->parser->mTitle, $this->parser->mOptions);

		if (!is_array($reset)) {
			$reset = [];
		}
		$reset = array_merge($reset, $this->parameters->getParameter('reset'));

		if (!is_array($eliminate)) {
			$eliminate = [];
		}
		$eliminate = array_merge($eliminate, $this->parameters->getParameter('eliminate'));
		if ($isParserTag === false) {
			//In tag mode 'eliminate' is the same as 'reset' for templates, categories, and images.
			if ($eliminate['templates']) {
				$reset['templates'] = true;
				$eliminate['templates'] = false;
			}
			if ($eliminate['categories']) {
				$reset['categories'] = true;
				$eliminate['categories'] = false;
			}
			if ($eliminate['images']) {
				$reset['images'] = true;
				$eliminate['images'] = false;
			}
		} else {
			if ($reset['templates']) {
				\DynamicPageListHooks::$createdLinks['resetTemplates'] = true;
			}
			if ($reset['categories']) {
				\DynamicPageListHooks::$createdLinks['resetCategories'] = true;
			}
			if ($reset['images']) {
				\DynamicPageListHooks::$createdLinks['resetImages'] = true;
			}
		}
		if (($isParserTag === false && $reset['links']) || $isParserTag === true) {
			if ($reset['links']) {
				\DynamicPageListHooks::$createdLinks['resetLinks'] = true;
			}
			//Register a hook to reset links which were produced during parsing DPL output.
			if (!is_array($wgHooks['ParserAfterTidy']) || !in_array('DynamicPageListHooks::endReset', $wgHooks['ParserAfterTidy'])) {
				$wgHooks['ParserAfterTidy'][] = 'DynamicPageListHooks::endReset';
			}
		}

		if (array_sum($eliminate)) {
			//Register a hook to reset links which were produced during parsing DPL output
			if (!is_array($wgHooks['ParserAfterTidy']) || !in_array('DynamicPageListHooks::endEliminate', $wgHooks['ParserAfterTidy'])) {
				$wgHooks['ParserAfterTidy'][] = 'DynamicPageListHooks::endEliminate';
			}

			if ($eliminate['links']) {
				//Trigger the mediawiki parser to find links, images, categories etc. which are contained in the DPL output.  This allows us to remove these links from the link list later.  If the article containing the DPL statement itself uses one of these links they will be thrown away!
				\DynamicPageListHooks::$createdLinks[0] = array();
				foreach ($parserOutput->getLinks() as $nsp => $link) {
					\DynamicPageListHooks::$createdLinks[0][$nsp] = $link;
				}
			}
			if ($eliminate['templates']) {
				\DynamicPageListHooks::$createdLinks[1] = array();
				foreach ($parserOutput->getTemplates() as $nsp => $tpl) {
					\DynamicPageListHooks::$createdLinks[1][$nsp] = $tpl;
				}
			}
			if ($eliminate['categories']) {
				\DynamicPageListHooks::$createdLinks[2] = $parserOutput->mCategories;
			}
			if ($eliminate['images']) {
				\DynamicPageListHooks::$createdLinks[3] = $parserOutput->mImages;
			}
		}
	}

	/**
	 * Sort an array of Article objects by the card suit symbol.
	 *
	 * @access	private
	 * @param	array	Article objects in an array.
	 * @return	array	Sorted objects
	 */
	private function cardSuitSort($articles) {
		$sortKeys = [];
		foreach ($articles as $key => $article) {
			$title  = preg_replace('/.*:/', '', $article->mTitle);
			$tokens  = preg_split('/ - */', $title);
			$newKey = '';
			foreach ($tokens as $token) {
				$initial = substr($token, 0, 1);
				if ($initial >= '1' && $initial <= '7') {
					$newKey .= $initial;
					$suit = substr($token, 1);
					if ($suit == '♣') {
						$newKey .= '1';
					} elseif ($suit == '♦') {
						$newKey .= '2';
					} elseif ($suit == '♥') {
						$newKey .= '3';
					} elseif ($suit == '♠') {
						$newKey .= '4';
					} elseif (strtolower($suit) == 'sa' || strtolower($suit) == 'nt') {
						$newKey .= '5 ';
					} else {
						$newKey .= $suit;
					}
				} elseif (strtolower($initial) == 'p') {
					$newKey .= '0 ';
				} elseif (strtolower($initial) == 'x') {
					$newKey .= '8 ';
				} else {
					$newKey .= $token;
				}
			}
			$sortKeys[$key] = $newKey;
		}
		asort($sortKeys);
		foreach ($sortKeys as $oldKey => $newKey) {
			$sortedArticles[] = $articles[$oldKey];
		}
		return $sortedArticles;
	}
}
?>