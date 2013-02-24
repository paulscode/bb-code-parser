<?php
	/* -- https://bitbucket.org/AMcBain/bb-code-parser
	   --
	   --
	   -- PHP BB-Code Parsing Library,
	   --
	   -- Copyright 2009-2013, A.McBain

	    Redistribution and use, with or without modification, are permitted provided that the following
	    conditions are met:

	       1. Redistributions of source code must retain the above copyright notice, this list of
	          conditions and the following disclaimer.
	       2. Redistributions of binaries must reproduce the above copyright notice, this list of
	          conditions and the following disclaimer in other materials provided with the distribution.
	       4. The name of the author may not be used to endorse or promote products derived from this
	          software without specific prior written permission.

	    THIS SOFTWARE IS PROVIDED BY THE AUTHOR ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING,
	    BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
	    ARE DISCLAIMED. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,
	    EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS
	    OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY
	    OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
	    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

	   --

	    While this software is released "as is", I don't mind getting bug reports.
	*/

	/*
	   Most of the supported code specifications were aquired from here: http://www.bbcode.org/reference.php

	   Due to the way this parser/formatter is designed, content of a code is cannot be relied on to be passed
	   to the escape function on a code instance in between the calling of the open and close functions. So
	   certain things otherwise workable might not be (such as using the content of a link as the argument if
	   no argument was given).

	   This parser/formatter does not support calling out to anonymous functions (callbacks) when a code with-
	   out an implementation is encountered. The parser/formatter would have to accept callbacks for all
	   methods available on BBCode (plus an extra parameter for the code name). This is not in the plan to be
	   added as a feature. Maybe an adventerous person could attempt this.
	*/

	/* Using the BBCodeParser:
		Note any of the inputs shown here can be skipped by sending null instead:
		ex:  new BBCodeParser(null, $settings);

		// Replace all defined codes with default settings
		$parser = new BBCodeParser();
		$output = $parser->format($input);

		// Replace all allowed codes with default settings
		$parser = new BBCodeParser({
			allowedCodes => array('b', 'i', 'u')
		});
		$output = $parser->format($input);

		// Replace all allowed codes with custom settings (not all codes have settings)
		$parser = new BBCodeParser({
			allowedCodes => array('b', 'i', 'u'),
			settings => array(
				'FontSizeUnit' => 'px'
			)
		});
		$output = $parser->format($input);

		// Replace the implementation for 'Bold'
		$parser = new BBCodeParser({
			allowedCodes => array('b', 'i', 'u'),
			settings : array(
				'FontSizeUnit' => 'px'
			),
			codes : array(
				'b' => new HTMLBoldBBCode()
			)
		});
		$output = $parser->format($input);
	*/


	// Standard interface to be implemented by all "BB-Codes"
	interface BBCode {
		// Name to be displayed, ex: Bold
		public function getCodeName();
		// Name of the code as written, ex: b
		// Display names *must not* start with /
		public function getDisplayName();
		// Whether or not this code has an end marker
		// Codes without an end marker should implement the open method, and leave the close method empty
		public function needsEnd();
		// Demotes whether a code's content should be parsed for other codes
		// Codes such as a [code][/code] block might not want their content parsed for other codes
		public function canHaveCodeContent();
		// Whether or not this code can have an argument
		public function canHaveArgument();
		// Whether or not this code must have an argument
		// For consistency, a code which cannot have an argument should return false here
		public function mustHaveArgument();
		// Denotes whether or not the parser should generate a closing code if the returned opening code is already in effect
		// This is called before a new code of a type is opened. Return null to indicate that no code should be auto closed
		// The code returned should be equivalent to the "display name" of the code to be closed, ex: 'b' not 'Bold'
		// Confusing? ex: '[*]foo, bar [*]baz!' (if auto close code is '*') generates '[*]foo, bar[/*][*]baz!'
		//            An "opening" [*] was recorded, so when it hit the second [*], it inserted a closing [/*] first
		public function getAutoCloseCodeOnOpen();
		public function getAutoCloseCodeOnClose();
		// Whether or not the given argument is valid
		// Codes which do not take an argument should return false and those which accept any value should return true
		public function isValidArgument($settings, $argument=null);
		// Whether or not the actual display name of a code is a valid parent for this code
		// The "actual display name" is 'ul' or 'ol', not "Unordered List", etc.
		// If the code isn't nested, 'GLOBAL' will be passed instead
		public function isValidParent($settings, $parent=null);
		// Escape content that will eventually be sent to the format function
		// Take care not to escape the content again inside the format function
		public function escape($settings, $content);
		// Returns a statement indicating the opening of something which contains content
		// (whatever that is in the output format/language returned)
		// $argument is the part after the equals in some BB-Codes, ex: [url=http://example.org]...[/url]
		// $closingCode is used when $allowOverlappingCodes is true and contains the code being closed
		//              (this is because all open codes are closed then reopened after the $closingCode is closed)
		public function open($settings, $argument=null, $closingCode=null);
		// Returns a statement indicating the closing of something which contains content
		// (whatever that is in the output format/language returned)
		// $argument is the part after the equals in some BB-Codes, ex: [url=http://example.org]...[/url]
		// $closingCode is used when $allowOverlappingCodes is true and cotnains the code being closed
		//              (this is because all open codes are closed then reopened after the $closingCode is closed)
		//              null is sent for to the code represented by $closingCode (it cannot 'force close' itself)
		public function close($settings, $argument=null, $closingCode=null);
	}

	// Class for the BB-Code Parser.
	// Each parser is immutable, each instance's settings, codes, etc, are "final" after the parser is created.
	class BBCodeParser {

		private $bbCodes = array();
        private $bbCodeCount = 0;

		// Mapped Array with all the default implementations of BBCodes.
		// It is not advised this be edited directly as this will affect all other calls.
		// Instead, pass a Mapped Array of only the codes to be overridden to the BBCodeParser_replace function.
		private function setupDefaultCodes() {
			$this->bbCodes = array(
				'GLOBAL'  => new HTMLGlobalBBCode(),
				'b'       => new HTMLBoldBBCode(),
				'i'       => new HTMLItalicBBCode(),
				'u'       => new HTMLUnderlineBBCode(),
				's'       => new HTMLStrikeThroughBBCode(),
				'font'    => new HTMLFontBBCode(),
				'size'    => new HTMLFontSizeBBCode(),
				'color'   => new HTMLColorBBCode(),
				'left'    => new HTMLLeftBBCode(),
				'center'  => new HTMLCenterBBCode(),
				'right'   => new HTMLLeftBBCode(),
				'quote'   => new HTMLQuoteBBCode(),
				'code'    => new HTMLCodeBBCode(),
				'codebox' => new HTMLCodeBoxBBCode(),
				'url'     => new HTMLLinkBBCode(),
				'img'     => new HTMLImageBBCode(),
				'ul'      => new HTMLUnorderedListBBCode(),
				'ol'      => new HTMLOrderedListBBCode(),
				'li'      => new HTMLListItemBBCode(),
				'list'    => new HTMLListBBCode(),
				'*'       => new HTMLStarBBCode()
			);
		}

		// The allowed codes (set up in the constructor)
		private $allowedCodes = array();

		// Mapped Array with properties which can be used by BBCode implementations to affect output.
		// It is not advised this be edited directly as this will affect all other calls.
		// Instead, pass a Mapped Array of only the properties to be overridden to the BBCodeParser_replace function.
		private $settings = array(
			'XHTML'                    => false,
			'FontSizeUnit'             => 'pt',
			'FontSizeMax'              => 48, /* Set to null to allow any font-size */
			'ColorAllowAdvFormats'     => false, /* Whether the rgb[a], hsl[a] color formats should be accepted */
			'QuoteTitleBackground'     => '#e4eaf2',
			'QuoteBorder'              => '1px solid gray',
			'QuoteBackground'          => 'white',
			'QuoteCSSClassName'        => 'quotebox-${by}', /* ${by} is the quote parameter ex: [quote=Waldo], ${by} = Waldo */
			'CodeTitleBackground'      => '#ffc29c',
			'CodeBorder'               => '1px solid gray',
			'CodeBackground'           => 'white',
			'CodeCSSClassName'         => 'codebox-${lang}', /* ${lang} is the code parameter ex: [code=PHP], ${lang} = php */
			'LinkUnderline'            => true,
			'LinkColor'                => 'blue',
			/*'ImageWidthMax'            => 640,*/ // Uncomment these to tell the BB-Code parser to use them
			/*'ImageHeightMax'           => 480,*/ // The default is to allow any size image
			/*'UnorderedListDefaultType' => 'circle',*/ // Uncomment these to tell the BB-Code parser to use this
			/*'OrderedListDefaultType'   => '1',     */ // default type if the given one is invalid **
			/*'ListDefaultType'          => 'circle' */ // ...
		);
		// ** Note that this affects whether a tag is printed out "as is" if a bad argument is given.
		// It may not affect those tags which can take "" or nothing as their argument
		// (they may assign a relevant default themselves).

		// See the constructor comment for details
		private $allOrNothing = true;
		private $handleOverlappingCodes = false;
		private $codeStartSymbol = '[';
		private $codeEndSymbol = ']';

		/*
		   Sets up the BB-Code parser with the given settings.
		   If null is passed for allowed codes, all are allowed. If no settings are passed, defaults are used.
		   These parameters are supplimentary and overrides, that is, they are in addition to the defaults
		   already included, but they will override an default if found.

		   These options are passed in via an object. Just don't define those which you want to use the default.

		   $allowedCodes is an array of "display names" (b, i, ...) that are allowed to be parsed and formatted
		                 in the output. If null is passed, all default codes are allowed.
		       Default: allow all defaults

		   $settings is a mapped array of settings which various formatter implementations may use to control output.
		       Default: use built in default settings

		   $codes is a mapped array of "display names" to implementations of BBCode which are used to format output.
		          Any codes with the same name as a default will replace the default implementation. If you also
		          specify allowedCodes, don't forget to include these.
		       Default: no supplementary codes

		   $replaceDefaults indicates whether the previous codes map should be used in place of all the defaults
		                    instead of supplementing it. If this is set to true, and no GLOBAL code implementation is
		                    provided in the codes map, a default one will be provided that just returns content given
		                    to it unescaped.
		       Default: false

		   $allOrNothing refers to what happens when an invalid code is found. If true, it stops returns the input.
		                 If false, it keeps on going (output may not display as expected).
		                 Codes which are not allowed or codes for which no formatter cannot be found are not invalid.
		       Default: true

		   $handleOverlappingCodes tells the parser to properly (forcefully) handle overlapping codes.
		                           This is done by closing open tags which overlap, then reopening them after
		                           the closed one. This will only work when $allOrNothing is false.
		       Default: false

		   $escapeContentOutput tells the parser whether or not it should escape the contents of BBCodes in the output.
		                        Content is any text not directely related to a BBCode itself. [b]this is content[/b]
		       Default: true

		   $codeStartSymbol is the symbol denoting the start of a code (default is [ for easy compatability)
		       Default: '['

		   $codeEndSymbol is the symbol denoting the end of a code (default is ] for easy compatability with BB-Code)
		       Default: ']'
		*/
		public function __construct($options=null) {
			$this->setupDefaultCodes();

			if($options) {
				$this->allOrNothing = BBCodeParser::isValidKey($options, 'allorNothing')? !!$options.allOrNothing : $this->allOrNothing;
				$this->handleOverlappingCodes = BBCodeParser::isValidKey($options, 'handleOverlappingCodes')? !!$options.handleOverlappingCodes : $this->handleOverlappingCodes;
				$this->escapeContentOutput = BBCodeParser::isValidKey($options, 'escapeContentOutput')? !!$options.escapeContentOutput : $this->escapeContentOutput;
				$this->codeStartSymbol = $options.codeStartSymbol || $this->codeStartSymbol;
				$this->codeEndSymbol = $options.codeEndSymbol || $this->codeEndSymbol;

				// Copy settings
				if($options['settings']) {
					foreach($options['settings'] as $key => $value) {
						$this->settings[$key] = $value.'';
					}
				}

				// Copy passed code implementations
				if(options['codes']) {

					if (options['replaceDefaults']) {
						$this->bbCodes = options['codes'];
					} else {
						foreach($options['codes'] as $key => $value) {
							if ($value instanceof BBCode) {
								$this->bbCodes[$key] = $value;
							}
						}
					}
				}
			}

			$this->bbCodeCount = count($this->bbCodes);

			// If no global bb-code implementation, provide a default one.
			if(BBCodeParser::isValidKey($this->bbCodes, 'GLOBAL') || !($this->bbCodes['GLOBAL'] instanceof BBCode)) {

				// This should not affect the bb-code count as if it is the only bb-code, the effect is
				// the same as if no bb-codes were allowed / supplied.
				$this->bbCodes['GLOBAL'] = new DefaultGlobalBBCode();
			}

			if($options && $options['allowedCodes'] && is_array($options['allowedCodes'])) {
				$this->allowedCodes = array_slice($options['allowedCodes'], 0);
			} else {
				foreach(array_keys($this->bbCodes) as $key) {
					$this->allowedCodes[] = $key;
				}
			}
		}

		// Parses and replaces allowed BBCodes with the settings given when this parser was created
		// $allOrNothing, $handleOverlapping, and $escapeContentOutput codes can be overridden per call
		public function format($input, $options=null) {

			$allOrNothing = ($options && BBCodeParser::isValidKey($options, 'allorNothing'))? !!$options.allOrNothing : $this->allOrNothing;
			$handleOverlappingCodes = ($options && BBCodeParser::isValidKey($options, 'handleOverlappingCodes'))? !!$options.handleOverlappingCodes : $this->handleOverlappingCodes;
			$escapeContentOutput = ($options && BBCodeParser::isValidKey($options, 'escapeContentOutput'))? !!$options.escapeContentOutput : $this->escapeContentOutput;

			// Why bother parsing if there's no codes to find?
			if($this->bbCodeCount > 0 && count($this->allowedCodes) > 0) {
				return $this->state_replace($input, $this->allowedCodes, $this->settings, $this->bbCodes, $allOrNothing, $handleOverlappingCodes, $escapeContentOutput, $this->codeStartSymbol, $this->codeEndSymbol);
			}

			return $input;
		}

		private function state_replace($input, $allowedCodes, $settings, $codes, $allOrNothing, $handleOverlappingCodes, $escapeContentOutput, $codeStartSymbol, $codeEndSymbol) {
			$output = '';

			// If no brackets, just dump it back out (don't spend time parsing it)
			if(strrpos($input, $codeStartSymbol) !== false && strrpos($input, $codeEndSymbol) !== false) {
				$queue = array(); // queue of codes and content
				$stack = array(); // stack of open codes

				// Iterate over input, finding start symbols
				$tokenizer = new BBCodeParser_MultiTokenizer($input);
				while($tokenizer->hasNextToken($codeStartSymbol)) {
					$before = $tokenizer->nextToken($codeStartSymbol);
					$code = $tokenizer->nextToken($codeEndSymbol);

					// If "valid" parse further
					if($code !== '') {

						// Store content before code
						if($before !== '') {
							$queue[] = new BBCodeParser_Token(BBCodeParser_Token::$CONTENT, $before);
						}

						// Parse differently depending on whether or not there's an argument
						$equals = strrpos($code, '=');
						if($equals) {
							$codeDisplayName = substr($code, 0, $equals);
							$codeArgument = substr($code, $equals + 1);
						} else {
							$codeDisplayName = $code;
							$codeArgument = null;
						}

						// End codes versus start codes
						if(substr($code, 0, 1) === '/') {
							$codeNoSlash = substr($codeDisplayName, 1);

							// Handle auto closing codes
							if(BBCodeParser::isValidKey($codes, $codeNoSlash) && ($autoCloseCode = $codes[$codeNoSlash]->getAutoCloseCodeOnClose()) &&
							   BBCodeParser::isValidKey($codes, $autoCloseCode) && in_array($autoCloseCode, $stack)) {

								$this->array_remove($stack, $autoCloseCode, true);
								$queue[] = new BBCodeParser_Token(BBCodeParser_Token::$CODE_END, '/'.$autoCloseCode);
							}

							$queue[] = new BBCodeParser_Token(BBCodeParser_Token::$CODE_END, $codeDisplayName);
							$codeDisplayName = $codeNoSlash;
						} else {

							// Handle auto closing codes
							if(BBCodeParser::isValidKey($codes, $codeDisplayName) && ($autoCloseCode = $codes[$codeDisplayName]->getAutoCloseCodeOnOpen()) &&
							   BBCodeParser::isValidKey($codes, $autoCloseCode) && in_array($autoCloseCode, $stack)) {

								$this->array_remove($stack, $autoCloseCode, true);
								$queue[] = new BBCodeParser_Token(BBCodeParser_Token::$CODE_END, '/'.$autoCloseCode);
							}

							$queue[] = new BBCodeParser_Token(BBCodeParser_Token::$CODE_START, $codeDisplayName, $codeArgument);
							$stack[] = $codeDisplayName;
						}

						// Check for codes with no implementation and codes which aren't allowed
						if(!BBCodeParser::isValidKey($codes, $codeDisplayName)) {
							$queue[count($queue) - 1]->status = BBCodeParser_Token::$NOIMPLFOUND;
						} else if(!in_array($codeDisplayName, $allowedCodes)) {
							$queue[count($queue) - 1]->status = BBCodeParser_Token::$NOTALLOWED;
						}

					} else if($code === '') {
						$queue[] = new BBCodeParser_Token(BBCodeParser_Token::$CONTENT, $before.'[]');
					}
				}

				// Get any text after the last end symbol
				$lastBits = substr($input, strrpos($input, $codeEndSymbol) + strlen($codeEndSymbol));
				if($lastBits !== '') {
					$queue[] = new BBCodeParser_Token(BBCodeParser_Token::$CONTENT, $lastBits);
				}

				// Find/mark all valid start/end code pairs
				$count = count($queue);
				for($i = 0; $i < $count; $i++) {
					$token = $queue[$i];

					// Handle undetermined and valid codes
					if($token->status !== BBCodeParser_Token::$NOIMPLFOUND && $token->status !== BBCodeParser_Token::$NOTALLOWED) {

						// Handle start and end codes
						if($token->type === BBCodeParser_Token::$CODE_START) {

							// Start codes which don't need an end are valid
							if(!$codes[$token->content]->needsEnd()) {
								$token->status = BBCodeParser_Token::$VALID;
							}

						} else if($token->type === BBCodeParser_Token::$CODE_END) {
							$content = substr($token->content, 1);

							// Ending codes for items which don't need an end are technically invalid, but since
							// the start code is valid (and self-contained) we'll turn them into regular content
							if(!$codes[$content]->needsEnd()) {
								$token->type = BBCodeParser_Token::$CONTENT;
								$token->status = BBCodeParser_Token::$VALID;
							} else {

								// Try our best to handle overlapping codes (they are a real PITA)
								if($handleOverlappingCodes) {
									$start = $this->state__findStartCodeOfType($queue, $content, $i);
								} else {
									$start = $this->state__findStartCodeWithStatus($queue, BBCodeParser_Token::$UNDETERMINED, $i);
								}

								// Handle valid end codes, mark others invalid
								if($start === -1 || $queue[$start]->content !== $content) {
									$token->status = BBCodeParser_Token::$INVALID;
								} else {
									$token->status = BBCodeParser_Token::$VALID;
									$token->matches = $start;
									$queue[$start]->status = BBCodeParser_Token::$VALID;
									$queue[$start]->matches = $i;
								}
							}
						}
					}

					// If all or nothing, just return the input (as we found 1 invalid code)
					if($allOrNothing && $token->status === BBCodeParser_Token::$INVALID) {
						return $input;
					}
				}

				// Empty the stack
				$stack = array();

				// Final loop to print out all the open/close tags as appropriate
				for($i = 0; $i < $count; $i++) {
					$token = $queue[$i];

					// Escape content tokens via their parent's escaping function
					if($token->type === BBCodeParser_Token::$CONTENT) {
						$parent = $this->state__findStartCodeWithStatus($queue, BBCodeParser_Token::$VALID, $i);
						$output .= (!$escapeContentOutput)? $token->content : ($parent === -1 || !BBCodeParser::isValidKey($codes, $queue[$parent]->content))? $codes['GLOBAL']->escape($settings, $token->content) : $codes[$queue[$parent]->content]->escape($settings, $token->content);

					// Handle start codes
					} else if($token->type === BBCodeParser_Token::$CODE_START) {
						$parent = null;

						// If undetermined or currently valid, validate against various codes rules
						if($token->status !== BBCodeParser_Token::$NOIMPLFOUND && $token->status !== BBCodeParser_Token::$NOTALLOWED) {
							$parent = $this->state__findParentStartCode($queue, $i);

							if(($token->status === BBCodeParser_Token::$UNDETERMINED && $codes[$token->content]->needsEnd()) ||
							   ($codes[$token->content]->canHaveArgument() && !$codes[$token->content]->isValidArgument($settings, $token->argument)) ||
							   (!$codes[$token->content]->canHaveArgument() && $token->argument) ||
							   ($codes[$token->content]->mustHaveArgument() && !$token->argument) ||
							   ($parent !== -1 && !$codes[$queue[$parent]->content]->canHaveCodeContent())) {

								$token->status = BBCodeParser_Token::$INVALID;
								// Both tokens in the pair should be marked
								if($token->status) {
									$queue[$token->matches]->status = BBCodeParser_Token::$INVALID;
								}

								// AllOrNothing, return input
								if($allOrNothing) return $input;
							}

							$parent = ($parent === -1)? 'GLOBAL' : $queue[$parent]->content;
						}

						// Check the parent code too ... some codes are only used within other codes
						if($token->status === BBCodeParser_Token::$VALID && $codes[$token->content]->isValidParent($settings, $parent)) {
							$output .= $codes[$token->content]->open($settings, $token->argument);

							// Store all open codes
							if($handleOverlappingCodes) $stack[] = $token;
						} else if($token->argument !== null) {
							$output .= $codeStartSymbol.$token->content.'='.$token->argument.$codeEndSymbol;
						} else {
							$output .= $codeStartSymbol.$token->content.$codeEndSymbol;
						}

					// Handle end codes
					} else if($token->type === BBCodeParser_Token::$CODE_END) {

						if($token->status === BBCodeParser_Token::$VALID) {
							$content = substr($token->content, 1);

							// Remove the closing code, close all open codes
							if($handleOverlappingCodes) {
								$scount = count($stack);

								// Codes must be closed in the same order they were opened
								for($j = $scount - 1; $j >= 0; $j--) {
									$jtoken = $stack[$j];
									$output .= $codes[$jtoken->content]->close($settings, $jtoken->argument, ($jtoken->content === $content)? null : $content);
								}

								// Removes matching open code
								$matchRef = &$queue[$token->matches];
								$this->array_removeRef($stack, $matchRef, true);
								unset($matchRef);
							} else {

								// Close the current code
								$output .= $codes[$content]->close($settings, $token->argument);
							}

							// Now reopen all remaing codes
							if($handleOverlappingCodes) {
								$scount = count($stack);

								for($j = 0; $j < $scount; $j++) {
									$jtoken = $stack[$j];
									$output .= $codes[$jtoken->content]->open($settings, $jtoken->argument, ($jtoken->content === $content)? null : $content);
								}
							}
						} else {
							$output .= $codeStartSymbol.$token->content.$codeEndSymbol;
						}
					}
				}
			} else {
				$output .= (!$escapeContentOutput)? $input : $codes['GLOBAL']->escape($settings, $input);
			}

			return $output;
		}

		// Finds the closest parent with a certain status to the given position, working backwards
		private function state__findStartCodeWithStatus(&$queue, $status, $position) {
			$found = false;
			$index = -1;

			for($i = $position - 1; $i >= 0 && !$found; $i--) {
				$found = $queue[$i]->type === BBCodeParser_Token::$CODE_START && $queue[$i]->status === $status;
				$index = $i;
			}

			return ($found)? $index : -1;
		}

		// Finds the closest valid parent with a certain content to the given position, working backwards
		private function state__findStartCodeOfType(&$queue, $content, $position) {
			$found = false;
			$index = -1;

			for($i = $position - 1; $i >= 0 && !$found; $i--) {
				$found = $queue[$i]->type === BBCodeParser_Token::$CODE_START &&
				         $queue[$i]->status === BBCodeParser_Token::$UNDETERMINED &&
						 $queue[$i]->content === $content;
				$index = $i;
			}

			return ($found)? $index : -1;
		}

		// Find the parent start-code of another code
		private function state__findParentStartCode(&$queue, $position) {
			$found = false;
			$index = -1;

			for($i = $position - 1; $i >= 0 && !$found; $i--) {
				$found = $queue[$i]->type === BBCodeParser_Token::$CODE_START &&
				         $queue[$i]->status === BBCodeParser_Token::$VALID &&
						 $queue[$i]->matches > $position;
				$index = $i;
			}

			return ($found)? $index : -1;
		}

		// Removes the given value from an array (match found by reference)
		private function array_removeRef(&$stack, $matchRef, $first=false) {
			$found = false;
			$count = count($stack);

			for($i = 0; $i < $count && !$found; $i++) {
				$stackRef = &$stack[$i];

				if($stackRef === $matchRef) {
					array_splice($stack, $i, 1);

					$found = true && $first;
					$count--;
					$i--;
				}

				unset($stackRef);
			}
		}

		// Removes the given value from an array (match found by reference)
		private function array_remove(&$stack, $match, $first=false) {
			$found = false;
			$count = count($stack);

			for($i = 0; $i < $count && !$found; $i++) {
				if($stack[$i] === $match) {
					array_splice($stack, $i, 1);

					$found = true && $first;
					$count--;
					$i--;
				}
			}
		}

		// Whether or not a key in an array is valid or not (is set, and is not null)
		public static function isValidKey(&$array, $key) {
			return isset($array[$key]);
		}

	}

	/*
	   A "multiple token" tokenizer.
	   This will not return the text between the last found token and the end of the string,
	   as no token will match "end of string". There is no special "end of string" token to
	   match against either, as with an arbitrary token to find, how does one know they are
	   "one from the end"?
	*/
	class BBCodeParser_MultiTokenizer {
		private $input = '';
		private $length = 0;
		private $position = 0;

		public function __construct($input, $position=0) {
			$this->input = $input.'';
			$this->length = strlen($this->input);
			$this->position = intval($position);
		}

		public function hasNextToken($delimiter=' ') {
			return strpos($this->input, $delimiter, min($this->length, $this->position)) !== false;
		}

		public function nextToken($delimiter=' ') {

			if($this->position >= $this->length) {
				return false;
			}

			$index = strpos($this->input, $delimiter, $this->position);
			if($index === false) {
				$index = $this->length;
			}

			$result = substr($this->input, $this->position, $index - $this->position);
			$this->position = $index + 1;

			return ($result !== false)? $result : '';
		}

		public function reset() {
			$this->position = false;
		}

	}

	// Class representing a BB-Code-oriented token
	class BBCodeParser_Token {
		public static $NONE = 'NONE';
		public static $CODE_START = 'CODE_START';
		public static $CODE_END = 'CODE_END';
		public static $CONTENT = 'CONTENT';

		public static $VALID = 'VALID';
		public static $INVALID = 'INVALID';
		public static $NOTALLOWED = 'NOTALLOWED';
		public static $NOIMPLFOUND = 'NOIMPLFOUND';
		public static $UNDETERMINED = 'UNDETERMINED';

		public $type = 'NONE';
		public $status = 'UNDETERMINED';
		public $content = '';
		public $argument = null;
		public $matches = null; // matching start/end code index

		public function __construct($type, $content, $argument=null) {
			$this->type = $type;
			$this->content = $content;
			$this->status = ($this->type === self::$CONTENT)? self::$VALID : self::$UNDETERMINED;
			$this->argument = $argument;
		}

	}

	class DefaultGlobalBBCode implements BBCode {
		public function getCodeName() { return 'GLOBAL'; }
		public function getDisplayName() { return 'GLOBAL'; }
		public function needsEnd() { return false; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return false; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return false; }
		public function isValidParent($settings, $parent=null) { return false; }
		public function escape($settings, $content) { return $content; }
		public function open($settings, $argument=null, $closingCode=null) { return ''; }
		public function close($settings, $argument=null, $closingCode=null) { return ''; }
	}

	  /************************/
	 /* HTML implementations */
	/************************/

	class HTMLGlobalBBCode implements BBCode {
		public function getCodeName() { return 'GLOBAL'; }
		public function getDisplayName() { return 'GLOBAL'; }
		public function needsEnd() { return false; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return false; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return false; }
		public function isValidParent($settings, $parent=null) { return false; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) { return ''; }
		public function close($settings, $argument=null, $closingCode=null) { return ''; }
	}

	class HTMLBoldBBCode implements BBCode {
		public function getCodeName() { return 'Bold'; }
		public function getDisplayName() { return 'b'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return false; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return false; }
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) { return '<b>'; }
		public function close($settings, $argument=null, $closingCode=null) { return '</b>'; }
	}

	class HTMLItalicBBCode implements BBCode {
		public function getCodeName() { return 'Italic'; }
		public function getDisplayName() { return 'i'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return false; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return false; }
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) { return '<i>'; }
		public function close($settings, $argument=null, $closingCode=null) { return '</i>'; }
	}

	class HTMLUnderlineBBCode implements BBCode {
		public function getCodeName() { return 'Underline'; }
		public function getDisplayName() { return 'u'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return false; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return false; }
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) { return '<u>'; }
		public function close($settings, $argument=null, $closingCode=null) { return '</u>'; }
	}

	class HTMLStrikeThroughBBCode implements BBCode {
		public function getCodeName() { return 'StrikeThrough'; }
		public function getDisplayName() { return 's'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return false; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return false; }
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) { return '<s>'; }
		public function close($settings, $argument=null, $closingCode=null) { return '</s>'; }
	}

	class HTMLFontSizeBBCode implements BBCode {
		public function getCodeName() { return 'Font Size'; }
		public function getDisplayName() { return 'size'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return true; }
		public function mustHaveArgument() { return true; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function isValidParent($settings, $parent=null) { return true; }
		public function isValidArgument($settings, $argument=null) {
			if(!BBCodeParser::isValidKey($settings, 'FontSizeMax') ||
			   (BBCodeParser::isValidKey($settings, 'FontSizeMax') && intval($settings['FontSizeMax']) <= 0)) {
				return intval($argument) > 0;
			}
			return intval($argument) > 0 && intval($argument) <= intval($settings['FontSizeMax']);
		}
		public function open($settings, $argument=null, $closingCode=null) {
			return '<span style="font-size: '.intval($argument).htmlspecialchars($settings['FontSizeUnit']).'">';
		}
		public function close($settings, $argument=null, $closingCode=null) {
			return '</span>';
		}
	}

	class HTMLColorBBCode implements BBCode {
		private static $browserColors = array('aliceblue'=>'1','antiquewhite'=>'1','aqua'=>'1','aquamarine'=>'1','azure'=>'1','beige'=>'1','bisque'=>'1','black'=>'1','blanchedalmond'=>'1','blue'=>'1','blueviolet'=>'1','brown'=>'1','burlywood'=>'1','cadetblue'=>'1','chartreuse'=>'1','chocolate'=>'1','coral'=>'1','cornflowerblue'=>'1','cornsilk'=>'1','crimson'=>'1','cyan'=>'1','darkblue'=>'1','darkcyan'=>'1','darkgoldenrod'=>'1','darkgray'=>'1','darkgreen'=>'1','darkkhaki'=>'1','darkmagenta'=>'1','darkolivegreen'=>'1','darkorange'=>'1','darkorchid'=>'1','darkred'=>'1','darksalmon'=>'1','darkseagreen'=>'1','darkslateblue'=>'1','darkslategray'=>'1','darkturquoise'=>'1','darkviolet'=>'1','deeppink'=>'1','deepskyblue'=>'1','dimgray'=>'1','dodgerblue'=>'1','firebrick'=>'1','floralwhite'=>'1','forestgreen'=>'1','fuchsia'=>'1','gainsboro'=>'1','ghostwhite'=>'1','gold'=>'1','goldenrod'=>'1','gray'=>'1','green'=>'1','greenyellow'=>'1','honeydew'=>'1','hotpink'=>'1','indianred'=>'1','indigo'=>'1','ivory'=>'1','khaki'=>'1','lavender'=>'1','lavenderblush'=>'1','lawngreen'=>'1','lemonchiffon'=>'1','lightblue'=>'1','lightcoral'=>'1','lightcyan'=>'1','lightgoldenrodyellow'=>'1','lightgrey'=>'1','lightgreen'=>'1','lightpink'=>'1','lightsalmon'=>'1','lightseagreen'=>'1','lightskyblue'=>'1','lightslategray'=>'1','lightsteelblue'=>'1','lightyellow'=>'1','lime'=>'1','limegreen'=>'1','linen'=>'1','magenta'=>'1','maroon'=>'1','mediumaquamarine'=>'1','mediumblue'=>'1','mediumorchid'=>'1','mediumpurple'=>'1','mediumseagreen'=>'1','mediumslateblue'=>'1','mediumspringgreen'=>'1','mediumturquoise'=>'1','mediumvioletred'=>'1','midnightblue'=>'1','mintcream'=>'1','mistyrose'=>'1','moccasin'=>'1','navajowhite'=>'1','navy'=>'1','oldlace'=>'1','olive'=>'1','olivedrab'=>'1','orange'=>'1','orangered'=>'1','orchid'=>'1','palegoldenrod'=>'1','palegreen'=>'1','paleturquoise'=>'1','palevioletred'=>'1','papayawhip'=>'1','peachpuff'=>'1','peru'=>'1','pink'=>'1','plum'=>'1','powderblue'=>'1','purple'=>'1','red'=>'1','rosybrown'=>'1','royalblue'=>'1','saddlebrown'=>'1','salmon'=>'1','sandybrown'=>'1','seagreen'=>'1','seashell'=>'1','sienna'=>'1','silver'=>'1','skyblue'=>'1','slateblue'=>'1','slategray'=>'1','snow'=>'1','springgreen'=>'1','steelblue'=>'1','tan'=>'1','teal'=>'1','thistle'=>'1','tomato'=>'1','turquoise'=>'1','violet'=>'1','wheat'=>'1','white'=>'1','whitesmoke'=>'1','yellow'=>'1','yellowgreen');
		public function getCodeName() { return 'Color'; }
		public function getDisplayName() { return 'color'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return true; }
		public function mustHaveArgument() { return true; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) {
			if($argument === null) return false;
			if(BBCodeParser::isValidKey(self::$browserColors, strtolower($argument)) ||
			   preg_match('/^#[\dabcdef]{3}$/i', $argument) > 0 ||
			   preg_match('/^#[\dabcdef]{6}$/i', $argument) > 0) {
				return true;
			}
			if(BBCodeParser::isValidKey($settings, 'ColorAllowAdvFormats') && $settings['ColorAllowAdvFormats'] &&
			  (preg_match('/^rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)$/i', $argument) > 0 ||
			   preg_match('/^rgba\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*((0?\.\d+)|1|0)\s*\)$/i', $argument) > 0 ||
			   preg_match('/^hsl\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}\s+%\)$/i', $argument) > 0 ||
			   preg_match('/^hsla\(\s*\d{1,3}\s*,\s*\d{1,3}\s+%,\s*\d{1,3}\s+%,\s*((0?\.\d+)|1|0)\s*\)$/i', $argument) > 0)) {
				return true;
			}
			return false;
		}
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) {
			return '<span style="color: '.htmlspecialchars($argument).'">';
		}
		public function close($settings, $argument=null, $closingCode=null) {
			return '</span>';
		}
	}

	class HTMLFontBBCode implements BBCode {
		public function getCodeName() { return 'Font'; }
		public function getDisplayName() { return 'font'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return true; }
		public function mustHaveArgument() { return true; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return $argument !== null; }
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) {
			return '<span style="font-family: \''.htmlspecialchars($argument).'\'">';
		}
		public function close($settings, $argument=null, $closingCode=null) {
			return '</span>';
		}
	}

	class HTMLLeftBBCode implements BBCode {
		public function getCodeName() { return 'Left'; }
		public function getDisplayName() { return 'left'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return false; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return false; }
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) {
			return ($closingCode === null)? '<div style="display: block; text-align: left">' : '';
		}
		public function close($settings, $argument=null, $closingCode=null) {
			return ($closingCode === null)? '</div>' : '';
		}
	}

	class HTMLCenterBBCode implements BBCode {
		public function getCodeName() { return 'Center'; }
		public function getDisplayName() { return 'center'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return false; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return false; }
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) {
			return ($closingCode === null)? '<div style="display: block; text-align: center">' : '';
		}
		public function close($settings, $argument=null, $closingCode=null) {
			return ($closingCode === null)? '</div>' : '';
		}
	}

	class HTMLRightBBCode implements BBCode {
		public function getCodeName() { return 'Right'; }
		public function getDisplayName() { return 'right'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return false; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return false; }
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) {
			return ($closingCode === null)? '<div style="display: block; text-align: right">' : '';
		}
		public function close($settings, $argument=null, $closingCode=null) {
			return ($closingCode === null)? '</div>' : '';
		}
	}

	class HTMLQuoteBBCode implements BBCode {
		public function getCodeName() { return 'Quote'; }
		public function getDisplayName() { return 'quote'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return true; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return true; }
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) {
			if($closingCode === null) {
				$box  = '<div style="display: block; margin-bottom: .5em; border: '.htmlspecialchars($settings['QuoteBorder']).'; background-color: '.htmlspecialchars($settings['QuoteBackground']).'">';
				$box .= '<div style="display: block; width: 100%; text-indent: .25em; border-bottom: '.htmlspecialchars($settings['QuoteBorder']).'; background-color: '.htmlspecialchars($settings['QuoteTitleBackground']).'">';
				$box .= 'QUOTE';
				if($argument) $box.= ' by '.htmlspecialchars($argument);
				$box .= '</div>';
				$box .= '<div ';
				if($argument) $box .= 'class="'.htmlspecialchars(str_replace('${by}', $argument, $settings['QuoteCSSClassName'])).'" ';
				$box .= 'style="overflow-x: auto; padding: .25em">';
				return $box;
			}
		}
		public function close($settings, $argument=null, $closingCode=null) {
			return ($closingCode === null)? '</div></div>' : '';
		}
	}

	class HTMLCodeBBCode implements BBCode {
		public function getCodeName() { return 'Code'; }
		public function getDisplayName() { return 'code'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return false; }
		public function canHaveArgument() { return true; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return true; }
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) {
			if($closingCode === null) {
				$box  = '<div style="display: block; margin-bottom: .5em; border: '.htmlspecialchars($settings['CodeBorder']).'; background-color: '.htmlspecialchars($settings['CodeBackground']).'">';
				$box .= '<div style="display: block; width: 100%; text-indent: .25em; border-bottom: '.htmlspecialchars($settings['CodeBorder']).'; background-color: '.htmlspecialchars($settings['CodeTitleBackground']).'">';
				$box .= 'CODE';
				if($argument) $box.= ' ('.htmlspecialchars($argument).')';
				$box .= '</div><pre ';
				if($argument) $box .= 'class="'.htmlspecialchars(str_replace('${lang}', $argument, $settings['CodeCSSClassName'])).'" ';
				$box .= 'style="overflow-x: auto; margin: 0; font-family: monospace; white-space: pre-wrap; padding: .25em">';
				return $box;
			}
			return '';
		}
		public function close($settings, $argument=null, $closingCode=null) {
			return ($closingCode === null)? '</pre></div>' : '';
		}
	}

	class HTMLCodeBoxBBCode implements BBCode {
		public function getCodeName() { return 'Code Box'; }
		public function getDisplayName() { return 'codebox'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return false; }
		public function canHaveArgument() { return true; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return true; }
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) {
			if($closingCode === null) {
				$box  = '<div style="display: block; margin-bottom: .5em; border: '.htmlspecialchars($settings['CodeBorder']).'; background-color: '.htmlspecialchars($settings['CodeBackground']).'">';
				$box .= '<div style="display: block; width: 100%; text-indent: .25em; border-bottom: '.htmlspecialchars($settings['CodeBorder']).'; background-color: '.htmlspecialchars($settings['CodeTitleBackground']).'">';
				$box .= 'CODE';
				if($argument) $box.= ' ('.htmlspecialchars($argument).')';
				$box .= '</div><pre ';
				if($argument) $box .= 'class="'.htmlspecialchars(str_replace('${lang}', $argument, $settings['CodeCSSClassName'])).'" ';
				$box .= 'style="height: 29ex; overflow-y: auto; margin: 0; font-family: monospace; white-space: pre-wrap; padding: .25em">';
				return $box;
			}
			return '';
		}
		public function close($settings, $argument=null, $closingCode=null) {
			return ($closingCode === null)? '</pre></div>' : '';
		}
	}

	class HTMLLinkBBCode implements BBCode {
		public function getCodeName() { return 'Link'; }
		public function getDisplayName() { return 'url'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return true; }
		public function mustHaveArgument() { return true; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return true; }
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) {
			$decoration = (!BBCodeParser::isValidKey($settings, 'LinkUnderline') || $settings['LinkUnderline'])? 'underline' : 'none';
			return '<a style="text-decoration: '.$decoration.'; color: '.htmlspecialchars($settings['LinkColor']).'" href="'.htmlspecialchars($argument).'">';
		}
		public function close($settings, $argument=null, $closingCode=null) {
			return '</a>';
		}
	}

	class HTMLImageBBCode implements BBCode {
		public function getCodeName() { return 'Image'; }
		public function getDisplayName() { return 'img'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return false; }
		public function canHaveArgument() { return true; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) {
			if($argument === null) return true;
			$args = explode('x', $argument);
			return count($args) === 2 && floatval($args[0]) === floor(floatval($args[0])) && floatval($args[1]) === floor(floatval($args[1]));
		}
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) {
			return ($closingCode === null)? '<img src="' : '';
		}
		public function close($settings, $argument=null, $closingCode=null) {
			if($closingCode === null) {
				if($argument !== null) {
					$args = explode('x', $argument);
					$width = intval($args[0]);
					$height = intval($args[1]);

					if(BBCodeParser::isValidKey($settings, 'ImageMaxWidth') && intval($settings['ImageMaxWidth']) !== 0) {
						$width = min($width, intval($settings['ImageMaxWidth']));
					}
					if(BBCodeParser::isValidKey($settings, 'ImageMaxHeight') && intval($settings['ImageMaxHeight']) !== 0) {
						$height = min($height, intval($settings['ImageMaxHeight']));
					}
					return '" alt="image" style="width: '.$width.'; height: '.$height.'"'.(($settings['XHTML'])? '/>' : '>');
				}
				return '" alt="image"' + (($settings['XHTML'])? '/>' : '>');
			}
			return '';
		}
	}

	class HTMLUnorderedListBBCode implements BBCode {
		private static $types = array(
			'circle' => 'circle',
			'disk'   => 'disk',
			'square' => 'square'
		);
		public function getCodeName() { return 'Unordered List'; }
		public function getDisplayName() { return 'ul'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return true; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) {
			if($argument === null) return true;
			return BBCodeParser::isValidKey(self::$types, $argument);
		}
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) {
			if($closingCode === null) {
				$key = null;

				if(BBCodeParser::isValidKey(self::$types, $argument)) $key = self::$types[$argument];
				if(!$key && BBCodeParser::isValidKey(self::$types, 'UnorderedListDefaultType') && BBCodeParser::isValidKey(self::$types, 'UnorderedListDefaultType')) {
					$argument = self::$types[$settings['UnorderedListDefaultType']];
				}
				if(!$key) $argument = self::$types['circle'];

				return '<ul style="list-style-type: '.htmlspecialchars($key).'">';
			}
			return '';
		}
		public function close($settings, $argument=null, $closingCode=null) {
			return ($closingCode === null)? '</ul>' : '';
		}
	}

	class HTMLOrderedListBBCode implements BBCode {
		private static $types = array(
			'1'      => 'decimal',
			'a'      => 'lower-alpha',
			'A'      => 'upper-alpha',
			'i'      => 'lower-roman',
			'I'      => 'upper-roman'
		);
		public function getCodeName() { return 'Unordered List'; }
		public function getDisplayName() { return 'ol'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return true; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) {
			if($argument === null) return true;
			return BBCodeParser::isValidKey(self::$types, $argument);
		}
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) {
			if($closingCode === null) {
				$key = null;

				if(BBCodeParser::isValidKey(self::$types, $argument)) $key = self::$types[$argument];
				if(!$key && BBCodeParser::isValidKey(self::$types, 'OrderedListDefaultType') && BBCodeParser::isValidKey(self::$types, 'OrderedListDefaultType')) {
					$argument = self::$types[$settings['OrderedListDefaultType']];
				}
				if(!$key) $argument = self::$types['1'];

				return '<ol style="list-style-type: '.htmlspecialchars($key).'">';
			}
			return '';
		}
		public function close($settings, $argument=null, $closingCode=null) {
			return ($closingCode === null)? '</ol>' : '';
		}
	}

	class HTMLListItemBBCode implements BBCode {
		public function getCodeName() { return 'List Item'; }
		public function getDisplayName() { return 'li'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return false; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return false; }
		public function isValidParent($settings, $parent=null) {
			return $parent === 'ul' || $parent === 'ol';
		}
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) { return '<li>'; }
		public function close($settings, $argument=null, $closingCode=null) { return '</li>'; }
	}

	class HTMLListBBCode implements BBCode {
		private static $ul_types = array(
			'circle' => 'circle',
			'disk'   => 'disk',
			'square' => 'square'
		);
		private static $ol_types = array(
			'1'      => 'decimal',
			'a'      => 'lower-alpha',
			'A'      => 'upper-alpha',
			'i'      => 'lower-roman',
			'I'      => 'upper-roman'
		);
		public function getCodeName() { return 'List'; }
		public function getDisplayName() { return 'list'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return true; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return null; }
		public function getAutoCloseCodeOnClose() { return '*'; }
		public function isValidArgument($settings, $argument=null) {
			if($argument === null) return true;
			return BBCodeParser::isValidKey(self::$ol_types, $argument) ||
			       BBCodeParser::isValidKey(self::$ul_types, $argument);
		}
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) {
			if($closingCode === null) {
				$key = getType($settings, $argument);
				return '<'.((BBCodeParser::isValidKey(self::$ol_types, $key))? 'ol' : 'ul').' style="list-style-type: '.htmlspecialchars($argument).'">';
			}
			return '';
		}
		public function close($settings, $argument=null, $closingCode=null) {
			if($closingCode === null) {
				$key = getType($settings, $argument);
				return '</'.((BBCodeParser::isValidKey(self::$ol_types, $key))? 'ol' : 'ul').'>';
			}
			return '';
		}
		private function getType(&$settings, $argument) {
			$key = null;

			if(BBCodeParser::isValidKey(self::$ul_types, $argument)) {
				$key = self::$ul_types[$argument];
			}
			if(!$key && BBCodeParser::isValidKey(self::$ol_types, $argument)) {
				$key = self::$ol_types[$argument];
			}
			if(!$key && BBCodeParser::isValidKey(self::$ul_types, 'ListDefaultType')) {
				$key = self::$ul_types[$settings['ListDefaultType']];
			}
			if(!$key && BBCodeParser::isValidKey($settings, 'ListDefaultType')) {
				$key = self::$ol_types[$settings['ListDefaultType']];
			}
			if(!$key) $key = self::$ul_types['circle'];

			return $key;
		}
	}

	class HTMLStarBBCode implements BBCode {
		public function getCodeName() { return 'Star'; }
		public function getDisplayName() { return '*'; }
		public function needsEnd() { return true; }
		public function canHaveCodeContent() { return true; }
		public function canHaveArgument() { return false; }
		public function mustHaveArgument() { return false; }
		public function getAutoCloseCodeOnOpen() { return '*'; }
		public function getAutoCloseCodeOnClose() { return null; }
		public function isValidArgument($settings, $argument=null) { return false; }
		public function isValidParent($settings, $parent=null) { return true; }
		public function escape($settings, $content) { return htmlspecialchars($content); }
		public function open($settings, $argument=null, $closingCode=null) { return '<li>'; }
		public function close($settings, $argument=null, $closingCode=null) { return '</li>'; }
	}
?>
