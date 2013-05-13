
	/* -- https://bitbucket.org/AMcBain/bb-code-parser
	   --
	   --
	   -- JS BB-Code Parsing Library
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

		// Replace all defined codes with default settings
		var parser = new BBCodeParser();
		var output = parser.format(input);

		// Specify the allowed codes
		var parser = new BBCodeParser({
			allowedCodes : ['b', 'i', 'u']
		});
		var output = parser.format(input);

		// Replace the implementation for 'Bold'. This is a noop as written, but shows how
		// to replace built-ins with custom implementations if so wished.
		var parser = new BBCodeParser({
			codes : {
				'b' : new HTMLBoldBBCode()
			}
		});
		var output = parser.format(input);

		// Override default settings. Custom settings can be specified to pass along info
		// to custom BB-code implementations but will be ignored by the default included
		// implementations.
		var parser = new BBCodeParser({
			settings : {
				'LinkColor' : 'green',
				'CustomSetting1' : 3
			}
		});
		var output = parser.format(input);

		// The above are just simple examples. Multiple properties can be set and combined
		// together when instantiating a parser.
	*/


	// Standard interface to be implemented by all "BB-Codes"
	function BBCode() {
		// Name to be displayed, ex: Bold
		this.getCodeName = function() {};
		// Name of the code as written, ex: b
		// Display names *must not* start with /
		this.getDisplayName = function() {};
		// Whether or not this code has an end marker
		// Codes without an end marker should implement the open method, and leave the close method empty
		this.needsEnd = function() {};
		// Demotes whether a code's content should be parsed for other codes
		// Codes such as a [code][/code] block might not want their content parsed for other codes
		this.canHaveCodeContent = function() {};
		// Whether or not this code can have an argument
		this.canHaveArgument = function() {};
		// Whether or not this code must have an argument
		// For consistency, a code which cannot have an argument should return false here
		this.mustHaveArgument = function() {};
		// Denotes whether or not the parser should generate a closing code if the returned opening code is already in effect
		// This is called before a new code of a type is opened. Return null to indicate that no code should be auto closed
		// The code returned should be equivalent to the "display name" of the code to be closed, ex: 'b' not 'Bold'
		// Confusing? ex: '[*]foo, bar [*]baz!' (if auto close code is '*') generates '[*]foo, bar[/*][*]baz!'
		//            An "opening" [*] was recorded, so when it hit the second [*], it inserted a closing [/*] first
		this.getAutoCloseCodeOnOpen = function() {};
		this.getAutoCloseCodeOnClose = function() {};
		// Whether or not the given argument is valid
		// Codes which do not take an argument should return false and those which accept any value should return true
		this.isValidArgument = function(settings, argument/*=null*/) {};
		// Whether or not the actual display name of a code is a valid parent for this code
		// The "actual display name" is 'ul' or 'ol', not "Unordered List", etc.
		// If the code isn't nested, 'GLOBAL' will be passed instead
		this.isValidParent = function(settings, parent/*=null*/) {};
		// Escape content that will eventually be sent to the format function
		// Take care not to escape the content again inside the format function
		this.escape = function(settings, content) {};
		// Returns a statement indicating the opening of something which contains content
		// (whatever that is in the output format/language returned)
		// argument is the part after the equals in some BB-Codes, ex: [url=http://example.org]...[/url]
		// closingCode is used when allowOverlappingCodes is true and contains the code being closed
		//             (this is because all open codes are closed then reopened after the closingCode is closed)
		this.open = function(settings, argument/*=null*/, closingCode/*=null*/) {};
		// Returns a statement indicating the closing of something which contains content
		// whatever that is in the output format/language returned)
		// argument is the part after the equals in some BB-Codes, ex: [url=http://example.org]...[/url]
		// closingCode is used when allowOverlappingCodes is true and cotnains the code being closed
		//             (this is because all open codes are closed then reopened after the closingCode is closed)
		//             null is sent for to the code represented by closingCode (it cannot 'force close' itthis)
		this.close = function(settings, argument/*=null*/, closingCode/*=null*/) {};
	}

	// PHP Compat functions
	var PHPC = {
		count: function(value) {
			var count = 0;
			for(var i in value) {
				count++;
			}
			return count;
		},
		in_array: function(needle, haystack) {

			// Faster ES5 function.
			if(haystack.indexOf) {
				return haystack.indexOf(needle) !== -1;
			}

			for(var i = 0; i < haystack.length; i++) {
				if(haystack[i] === needle) {
					return true;
				}
			}
			return false;
		},
		floatval: function(value) {
			return parseFloat(value) || 0;
		},
		intval: function(value) {
			var number = Number(value);
			return (isNaN(number))? 0 : number;
		},
		htmlspecialchars: function(value) {
			if(!value) return '';
			return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
		},
		// Not really a function in PHP, just a fact of how its = operator copies arrays by default.
		copy: function(value) {
			if(!value) return value;

			var result = {};
			for (var key in value) {
				result[key] = value;
			}
			return result;
		}
	}

	/*
	   Sets up the BB-Code parser with the given settings.
	   If a falsy value is passed for allowed codes, all are allowed. If no settings are passed, defaults are used.
	   These parameters are supplimentary and overrides, that is, they are in addition to the defaults already
	   included, but they will override an default if found.

	   Theses options are passed in via an object. Just don't define those for which you want to use the default.

	   allowedCodes is an array of "display names" (b, i, ...), it defines which that are allowed to be parsed and formatted
	                in the output. If nothing is passed, all default codes are allowed.
	       Default: allow all defaults

	   settings is a mapped array of settings which various formatter implementations may use to control output.
	       Default: use built in default settings

	   codes is a map of "display names" to implementations of BBCode which are used to format output.
	          Any codes with the same name as a default will replace the default implementation. If you also
	          specify allowedCodes, don't forget to include these.
	       Default: no supplementary codes

	   replaceDefaults indicates whether the previous codes map should be used in place of all the defaults
	                    instead of supplementing it. If this is set to true, and no GLOBAL code implementation is
	                    provided in the codes map, a default one will be provided that just returns content given
	                    to it unescaped.
	       Default: false

	   allOrNothing refers to what happens when an invalid code is found. If true, it stops returns the input.
	                If false, it keeps on going (output may not display as expected).
	                Codes which are not allowed or codes for which no formatter cannot be found are not invalid.
	       Default: true

	   handleOverlappingCodes tells the parser to properly (forcefully) handle overlapping codes.
	                          This is done by closing open tags which overlap, then reopening them after
	                          the closed one. This will only work when allOrNothing is false.
	       Default: false

	   escapeContentOutput tells the parser whether or not it should escape the contents of BBCodes in the output.
	                       Content is any text not directely related to a BBCode itself. [b]this is content[/b]
	       Default: true

	   codeStartSymbol is the symbol denoting the start of a code (default is [ for easy compatability)
	       Default: '['

	   codeEndSymbol is the symbol denoting the end of a code (default is ] for easy compatability with BB-Code)
	       Default: ']'
	*/
	// Class for the BB-Code Parser.
	// Each parser is immutable, each instance's settings, codes, etc, are "final" after the parser is created.
	function BBCodeParser(options) {

		var _bbCodes = {};
		var _bbCodeCount = 0;

		// Mapped Array with all the default implementations of BBCodes.
		// It is not advised this be edited directly as this will affect all other calls.
		// Instead, pass a Mapped Array of only the codes to be overridden to the BBCodeParser_replace function.
		function setupDefaultCodes() {
			_bbCodes = {
				'GLOBAL'  : new HTMLGlobalBBCode(),
				'b'       : new HTMLBoldBBCode(),
				'i'       : new HTMLItalicBBCode(),
				'u'       : new HTMLUnderlineBBCode(),
				's'       : new HTMLStrikeThroughBBCode(),
				'font'    : new HTMLFontBBCode(),
				'size'    : new HTMLFontSizeBBCode(),
				'color'   : new HTMLColorBBCode(),
				'left'    : new HTMLLeftBBCode(),
				'center'  : new HTMLCenterBBCode(),
				'right'   : new HTMLLeftBBCode(),
				'quote'   : new HTMLQuoteBBCode(),
				'code'    : new HTMLCodeBBCode(),
				'codebox' : new HTMLCodeBoxBBCode(),
				'url'     : new HTMLLinkBBCode(),
				'img'     : new HTMLImageBBCode(),
				'ul'      : new HTMLUnorderedListBBCode(),
				'ol'      : new HTMLOrderedListBBCode(),
				'li'      : new HTMLListItemBBCode(),
				'list'    : new HTMLListBBCode(),
				'*'       : new HTMLStarBBCode()
			};
		}

		// The allowed codes (set up in the constructor)
		var _allowedCodes = [];

		// Mapped Array with properties which can be used by BBCode implementations to affect output.
		// It is not advised this be edited directly as this will affect all other calls.
		// Instead, pass a Mapped Array of only the properties to be overridden to the BBCodeParser_replace function.
		var _settings = {
			'XHTML'                    : false,
			'FontSizeUnit'             : 'px',
			'FontSizeMax'              : 48,              // Set to null to allow any font-size
			'ColorAllowAdvFormats'     : false,           // Whether the rgb[a], hsl[a] color formats should be accepted
			'QuoteTitleBackground'     : '#e4eaf2',
			'QuoteBorder'              : '1px solid gray',
			'QuoteBackground'          : 'white',
			'QuoteCSSClassName'        : 'quotebox-{by}', // {by} is the quote parameter ex: [quote=Waldo], {by} = Waldo
			'CodeTitleBackground'      : '#ffc29c',
			'CodeBorder'               : '1px solid gray',
			'CodeBackground'           : 'white',
			'CodeCSSClassName'         : 'codebox-{lang}', // {lang} is the code parameter ex: [code=PHP], {lang} = php
			'LinkUnderline'            : true,
			'LinkColor'                : 'blue'//,
			//'ImageWidthMax'            : 640,              // Uncomment these to tell the BB-Code parser to use them
			//'ImageHeightMax'           : 480,              // The default is to allow any size image
			//'UnorderedListDefaultType' : 'disk',           // Uncomment these to tell the BB-Code parser to use this
			//'OrderedListDefaultType'   : '1',              // default type if the given one is invalid **
			//'ListDefaultType'          : 'disk'            // ...
		};
		// ** Note that this affects whether a tag is printed out "as is" if a bad argument is given.
		// It may not affect those tags which can take "" or nothing as their argument
		// (they may assign a relevant default themselves).

		// See the constructor comment for details
		var _allOrNothing = true;
		var _handleOverlappingCodes = false;
		var _escapeContentOutput = true;
		var _codeStartSymbol = '[';
		var _codeEndSymbol = ']';


		  /**************************/
		 /* START CONSTRUCTOR CODE */
		/**************************/

		setupDefaultCodes();

		if(options) {
			_allOrNothing = ('allOrNothing' in options)? options.allOrNothing : _allOrNothing;
			_handleOverlappingCodes = ('handleOverlappingCodes' in options)? options.handleOverlappingCodes : _handleOverlappingCodes;
			_escapeContentOutput = ('escapeContentOutput' in options)? options.escapeContentOutput : _escapeContentOutput;
			_codeStartSymbol = options.codeStartSymbol || _codeStartSymbol;
			_codeEndSymbol = options.codeEndSymbol || _codeEndSymbol;

			// Copy settings
			var key;
			if(options.settings) {
				for(key in options.settings) {
					_settings[key] = options.settings[key];
				}
			}

			// Copy passed code implementations
			if(options.codes) {

				if(options.replaceDefaults) {
					_bbCodes = PHPC.copy(options.codes);
				} else {
					for(key in options.codes) {
						_bbCodes[key] = options.codes[key];
					}
				}
			}
		}

		_bbCodeCount = PHPC.count(_bbCodes);

		// If no global bb-code implementation, provide a default one.
		if(!_bbCodes['GLOBAL']) {

			// This should not affect the bb-code count as if it is the only bb-code, the effect is
			// the same as if no bb-codes were allowed / supplied.
			_bbCodes['GLOBAL'] = new DefaultGlobalBBCode();
		}

		if(options && options.allowedCodes) {
			_allowedCodes = options.allowedCodes.slice(0);
		} else {
			for(key in _bbCodes) {
				_allowedCodes.push(key);
			}
		}

		  /************************/
		 /* END CONSTRUCTOR CODE */
		/************************/


		// Parses and replaces allowed BBCodes with the settings given when this parser was created
		// allOrNothing, handleOverlapping, and escapeContentOutput can be overridden per call
		this.format = function(input, options) {

			var allOrNothing = (options && 'allOrNothing' in options)? options.allOrNothing : _allOrNothing;
			var handleOverlappingCodes = (options && 'handleOverlappingCodes' in options)? options.handleOverlappingCodes : _handleOverlappingCodes;
			var escapeContentOutput = (options && 'escapeContentOutput' in options)? options.escapeContentOutput : _escapeContentOutput;

			// Why bother parsing if there's no codes to find?
			if(_bbCodeCount > 0 && _allowedCodes.length > 0) {
				return state_replace(input, _allowedCodes, _settings, _bbCodes, allOrNothing, handleOverlappingCodes, escapeContentOutput, _codeStartSymbol, _codeEndSymbol);
			}

			return input;
		};

		function state_replace(input, allowedCodes, settings, codes, allOrNothing, handleOverlappingCodes, escapeContentOutput, codeStartSymbol, codeEndSymbol) {
			var output = '';

			// If no brackets, just dump it back out (don't spend time parsing it)
			if(input.lastIndexOf(codeStartSymbol) !== -1 && input.lastIndexOf(codeEndSymbol) !== -1) {
				var queue = []; // queue of codes and content
				var stack = []; // stack of open codes

				// Iterate over input, finding start symbols
				var tokenizer = new BBCodeParser_MultiTokenizer(input);
				while(tokenizer.hasNextToken(codeStartSymbol)) {
					var before = tokenizer.nextToken(codeStartSymbol);
					var code = tokenizer.nextToken(codeEndSymbol);

					// If "valid" parse further
					if(code !== '') {

						// Store content before code
						if(before !== '') {
							queue.push(new BBCodeParser_Token(BBCodeParser_Token.CONTENT, before));
						}

						// Parse differently depending on whether or not there's an argument
						var codeDisplayName, codeArgument;
						var equals = code.lastIndexOf('=');
						if(equals !== -1) {
							codeDisplayName = code.substr(0, equals);
							codeArgument = code.substr(equals + 1);
						} else {
							codeDisplayName = code;
							codeArgument = null;
						}

						// End codes versus start codes
						var autoCloseCode;
						if(code.substr(0, 1) === '/') {
							var codeNoSlash = codeDisplayName.substr(1);

							// Handle auto closing codes
							if(BBCodeParser.isValidKey(codes, codeNoSlash) && (autoCloseCode = codes[codeNoSlash].getAutoCloseCodeOnClose()) &&
							   BBCodeParser.isValidKey(codes, autoCloseCode) && PHPC.in_array(autoCloseCode, stack)) {

								array_remove(stack, autoCloseCode, true);
								queue.push(new BBCodeParser_Token(BBCodeParser_Token.CODE_END, '/' + autoCloseCode));
							}

							queue.push(new BBCodeParser_Token(BBCodeParser_Token.CODE_END, codeDisplayName));
							codeDisplayName = codeNoSlash;
						} else {

							// Handle auto closing codes
							if(BBCodeParser.isValidKey(codes, codeDisplayName) && (autoCloseCode = codes[codeDisplayName].getAutoCloseCodeOnOpen()) &&
							   BBCodeParser.isValidKey(codes, autoCloseCode) && PHPC.in_array(autoCloseCode, stack)) {

								array_remove(stack, autoCloseCode, true);
								queue.push(new BBCodeParser_Token(BBCodeParser_Token.CODE_END, '/' + autoCloseCode));
							}

							queue.push(new BBCodeParser_Token(BBCodeParser_Token.CODE_START, codeDisplayName, codeArgument));
							stack.push(codeDisplayName);
						}

						// Check for codes with no implementation and codes which aren't allowed
						if(!BBCodeParser.isValidKey(codes, codeDisplayName)) {
							queue[queue.length - 1].status = BBCodeParser_Token.NOIMPLFOUND;
						} else if(!PHPC.in_array(codeDisplayName, allowedCodes)) {
							queue[queue.length - 1].status = BBCodeParser_Token.NOTALLOWED;
						}

					} else if(code === '') {
						queue.push(new BBCodeParser_Token(BBCodeParser_Token.CONTENT, before + '[]'));
					}
				}

				// Get any text after the last end symbol
				var lastBits = tokenizer.positionToEndToken();
				if(lastBits !== '') {
					queue.push(new BBCodeParser_Token(BBCodeParser_Token.CONTENT, lastBits));
				}

				// Find/mark all valid start/end code pairs
				var count = queue.length;
				for(i = 0; i < count; i++) {
					var token = queue[i];

					// Handle undetermined and valid codes
					if(token.status !== BBCodeParser_Token.NOIMPLFOUND && token.status !== BBCodeParser_Token.NOTALLOWED) {

						// Handle start and end codes
						if(token.type === BBCodeParser_Token.CODE_START) {

							// Start codes which don't need an end are valid
							if(!codes[token.content].needsEnd()) {
								token.status = BBCodeParser_Token.VALID;
							}

						} else if(token.type === BBCodeParser_Token.CODE_END) {
							content = token.content.substr(1);

							// Ending codes for items which don't need an end are technically invalid, but since
							// the start code is valid (and this-contained) we'll turn them into regular content
							if(!codes[content].needsEnd()) {
								token.type = BBCodeParser_Token.CONTENT;
								token.status = BBCodeParser_Token.VALID;
							} else {

								// Try our best to handle overlapping codes (they are a real PITA)
								var start;
								if(handleOverlappingCodes) {
									start = state__findStartCodeOfType(queue, content, i);
								} else {
									start = state__findStartCodeWithStatus(queue, BBCodeParser_Token.UNDETERMINED, i);
								}

								// Handle valid end codes, mark others invalid
								if(start === -1 || queue[start].content !== content) {
									token.status = BBCodeParser_Token.INVALID;
								} else {
									token.status = BBCodeParser_Token.VALID;
									token.matches = start;
									queue[start].status = BBCodeParser_Token.VALID;
									queue[start].matches = i;
								}
							}
						}
					}

					// If all or nothing, just return the input (as we found 1 invalid code)
					if(allOrNothing && token.status === BBCodeParser_Token.INVALID) {
						return input;
					}
				}

				// Empty the stack
				stack = [];

				// Final loop to print out all the open/close tags as appropriate
				for(i = 0; i < count; i++) {
					var parent, token = queue[i];

					// Escape content tokens via their parent's escaping function
					if(token.type === BBCodeParser_Token.CONTENT) {
						parent = state__findStartCodeWithStatus(queue, BBCodeParser_Token.VALID, i);
						output += (!escapeContentOutput)? token.content : (parent === -1 || !BBCodeParser.isValidKey(codes, queue[parent].content))? codes['GLOBAL'].escape(settings, token.content) : codes[queue[parent].content].escape(settings, token.content);

					// Handle start codes
					} else if(token.type === BBCodeParser_Token.CODE_START) {
						parent = null;

						// If undetermined or currently valid, validate against various codes rules
						if(token.status !== BBCodeParser_Token.NOIMPLFOUND && token.status !== BBCodeParser_Token.NOTALLOWED) {
							parent = state__findParentStartCode(queue, i);

							if((token.status === BBCodeParser_Token.UNDETERMINED && codes[token.content].needsEnd()) ||
							   (codes[token.content].canHaveArgument() && !codes[token.content].isValidArgument(settings, token.argument)) || 
							   (!codes[token.content].canHaveArgument() && token.argument) ||
							   (codes[token.content].mustHaveArgument() && !token.argument) ||
							   (parent !== -1 && !codes[queue[parent].content].canHaveCodeContent())) {

								token.status = BBCodeParser_Token.INVALID;
								// Both tokens in the pair should be marked
								if(token.matches) {
									queue[token.matches].status = BBCodeParser_Token.INVALID;
								}

								if(allOrNothing) return input;
							}

							parent = (parent === -1)? 'GLOBAL' : queue[parent].content;
						}

						// Check the parent code too ... some codes are only used within other codes
						if(!codes[token.content].isValidParent(settings, parent)) {

							if(token.matches) {
								queue[token.matches].status = BBCodeParser_Token.INVALID;
							}
							token.status = BBCodeParser_Token.INVALID;

							if(allOrNothing) return input;
						}

						if(token.status === BBCodeParser_Token.VALID) {
							output += codes[token.content].open(settings, token.argument);

							// Store all open codes
							if(handleOverlappingCodes) stack.push(token);
						} else if(token.argument !== null) {
							output += codeStartSymbol + token.content + '=' + token.argument + codeEndSymbol;
						} else {
							output += codeStartSymbol + token.content + codeEndSymbol;
						}

					// Handle end codes
					} else if(token.type === BBCodeParser_Token.CODE_END) {

						if(token.status === BBCodeParser_Token.VALID) {
							var content = token.content.substr(1);

							// Remove the closing code, close all open codes
							if(handleOverlappingCodes) {

								// Codes must be closed in the same order they were opened
								for(var j = stack.length - 1; j >= 0; j--) {
									var jtoken = stack[j];
									output += codes[jtoken.content].close(settings, jtoken.argument, (jtoken.content === content)? null : content);
								}

								// Removes matching open code
								array_remove(stack, queue[token.matches], true);
							} else {

								// Close the current code
								output += codes[content].close(settings, token.argument);
							}

							// Now reopen all remaing codes
							if(handleOverlappingCodes) {

								for(var j = 0; j < stack.length; j++) {
									var jtoken = stack[j];
									output += codes[jtoken.content].open(settings, jtoken.argument, (jtoken.content === content)? null : content);
								}
							}
						} else {
							output += codeStartSymbol + token.content + codeEndSymbol;
						}
					}
				}
			} else {
				output += (!escapeContentOutput)? input : codes['GLOBAL'].escape(settings, input);
			}

			return output;
		};

		// Finds the closest parent with a certain status to the given position, working backwards
		function state__findStartCodeWithStatus(queue, status, position) {

			for(var i = position - 1; i >= 0; i--) {
				if(queue[i].type === BBCodeParser_Token.CODE_START && queue[i].status === status) {
					return i;
				}
			}
			return -1;
		};

		// Finds the closest valid parent with a certain content to the given position, working backwards
		function state__findStartCodeOfType(queue, content, position) {

			for(var i = position - 1; i >= 0; i--) {
				if(queue[i].type === BBCodeParser_Token.CODE_START && queue[i].status === BBCodeParser_Token.UNDETERMINED &&
						queue[i].content === content) {
					return i;
				}
			}
			return -1;
		};

		// Find the parent start-code of another code
		function state__findParentStartCode(queue, position) {

			for(var i = position - 1; i >= 0; i--) {
				if(queue[i].type === BBCodeParser_Token.CODE_START && queue[i].status === BBCodeParser_Token.VALID &&
						queue[i].matches > position) {
					return i;
				}
			}
			return -1;
		};

		// Removes the given value from an array (match found by reference)
		function array_remove(stack, match, first) {
			if(first === undefined) first = false;

			count = stack.length;
			for(i = 0; i < count; i++) {

				if(stack[i] === match) {
					stack.splice(stack, i, 1);

					if(first) {
						return;
					}
					count--;
					i--;
				}
			}
		};

	}
	// Whether or not a key in an array is valid or not (is set, and is not undefined or null)
	BBCodeParser.isValidKey = function(array, key) {
		return key in array && array[key] !== undefined && array[key] !== null;
	};


	/*
	   A "multiple token" tokenizer.
	   This will not return the text between the last found token and the end of the string,
	   as no token will match "end of string". There is no special "end of string" token to
	   match against either, as with an arbitrary token to find, how does one know they are
	   "one from the end"?
	*/
	function BBCodeParser_MultiTokenizer(input, position) {
		var length = 0;

		if(position === undefined) position = 0;
		input = input + '';
		length = input.length;
		position = PHPC.intval(position);

		this.positionToEndToken = function() {
			return input.substring(position);
		};

		this.hasNextToken = function(delimiter) {
			if(delimiter === undefined) delimiter = ' ';
			return input.indexOf(delimiter, Math.min(length, position)) !== -1;
		}

		this.nextToken = function(delimiter) {
			if(delimiter === undefined) delimiter = ' ';

			if(position >= length) {
				return null;
			}

			var index = input.indexOf(delimiter, position);
			if(index === -1) {
				index = length;
			}

			var result = input.substr(position, index - position);
			position = index + 1;

			return result;
		}

		this.reset = function() {
			position = 0;
		}

	}

	// Class representing a BB-Code-oriented token
	function BBCodeParser_Token(type, content, argument) {

		this.type = BBCodeParser_Token.NONE;
		this.status = BBCodeParser_Token.UNDETERMINED;
		this.content = '';
		this.argument = null;
		this.matches = null; // matching start/end code index

		if(argument === undefined) argument = null;
		this.type = type;
		this.content = content;
		this.status = (this.type === BBCodeParser_Token.CONTENT)? BBCodeParser_Token.VALID : BBCodeParser_Token.UNDETERMINED;
		this.argument = argument;

	}
	BBCodeParser_Token.NONE = 'NONE';
	BBCodeParser_Token.CODE_START = 'CODE_START';
	BBCodeParser_Token.CODE_END = 'CODE_END';
	BBCodeParser_Token.CONTENT = 'CONTENT';

	BBCodeParser_Token.VALID = 'VALID';
	BBCodeParser_Token.INVALID = 'INVALID';
	BBCodeParser_Token.NOTALLOWED = 'NOTALLOWED';
	BBCodeParser_Token.NOIMPLFOUND = 'NOIMPLFOUND';
	BBCodeParser_Token.UNDETERMINED = 'UNDETERMINED';

	function DefaultGlobalBBCode() {
		this.getCodeName = function() { return 'GLOBAL'; }
		this.getDisplayName = function() { return 'GLOBAL'; }
		this.needsEnd = function() { return false; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return false; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return false; }
		this.isValidParent = function(settings, parent) { return false; }
		this.escape = function(settings, content) { return content; }
		this.open = function(settings, argument, closingCode) { return ''; }
		this.close = function(settings, argument, closingCode) { return ''; }
	}
	DefaultGlobalBBCode.prototype = new BBCode;

	  /************************/
	 /* HTML implementations */
	/************************/

	function HTMLGlobalBBCode() {
		this.getCodeName = function() { return 'GLOBAL'; }
		this.getDisplayName = function() { return 'GLOBAL'; }
		this.needsEnd = function() { return false; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return false; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return false; }
		this.isValidParent = function(settings, parent) { return false; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) { return ''; }
		this.close = function(settings, argument, closingCode) { return ''; }
	}
	HTMLGlobalBBCode.prototype = new BBCode;

	function HTMLBoldBBCode() {
		this.getCodeName = function() { return 'Bold'; }
		this.getDisplayName = function() { return 'b'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return false; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return false; }
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) { return '<b>'; }
		this.close = function(settings, argument, closingCode) { return '</b>'; }
	}
	HTMLBoldBBCode.prototype = new BBCode;

	function HTMLItalicBBCode() {
		this.getCodeName = function() { return 'Italic'; }
		this.getDisplayName = function() { return 'i'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return false; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return false; }
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) { return '<i>'; }
		this.close = function(settings, argument, closingCode) { return '</i>'; }
	}
	HTMLItalicBBCode.prototype = new BBCode;

	function HTMLUnderlineBBCode() {
		this.getCodeName = function() { return 'Underline'; }
		this.getDisplayName = function() { return 'u'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return false; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return false; }
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) { return '<u>'; }
		this.close = function(settings, argument, closingCode) { return '</u>'; }
	}
	HTMLUnderlineBBCode.prototype = new BBCode;

	function HTMLStrikeThroughBBCode() {
		this.getCodeName = function() { return 'StrikeThrough'; }
		this.getDisplayName = function() { return 's'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return false; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return false; }
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) { return '<s>'; }
		this.close = function(settings, argument, closingCode) { return '</s>'; }
	}
	HTMLStrikeThroughBBCode.prototype = new BBCode;

	function HTMLFontSizeBBCode() {
		this.getCodeName = function() { return 'Font Size'; }
		this.getDisplayName = function() { return 'size'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return true; }
		this.mustHaveArgument = function() { return true; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.isValidParent = function(settings, parent) { return true; }
		this.isValidArgument = function(settings, argument) { return PHPC.intval(argument) > 0; }
		this.isValidArgument = function(settings, argument) {
			if(!BBCodeParser.isValidKey(settings, 'FontSizeMax') ||
			   (BBCodeParser.isValidKey(settings, 'FontSizeMax') && PHPC.intval(settings['FontSizeMax']) <= 0)) {
				return PHPC.intval(argument) > 0;
			}
			return PHPC.intval(argument) > 0 && PHPC.intval(argument) <= PHPC.intval(settings['FontSizeMax']);
		}
		this.open = function(settings, argument, closingCode) { 
			return '<span style="font-size: ' + PHPC.intval(argument) + PHPC.htmlspecialchars(settings['FontSizeUnit']) + '">';
		}
		this.close = function(settings, argument, closingCode) {
			return '</span>';
		}
	}
	HTMLFontSizeBBCode.prototype = new BBCode;

	function HTMLColorBBCode() {
		var browserColors = {'aliceblue':'1','antiquewhite':'1','aqua':'1','aquamarine':'1','azure':'1','beige':'1','bisque':'1','black':'1','blanchedalmond':'1','blue':'1','blueviolet':'1','brown':'1','burlywood':'1','cadetblue':'1','chartreuse':'1','chocolate':'1','coral':'1','cornflowerblue':'1','cornsilk':'1','crimson':'1','cyan':'1','darkblue':'1','darkcyan':'1','darkgoldenrod':'1','darkgray':'1','darkgreen':'1','darkkhaki':'1','darkmagenta':'1','darkolivegreen':'1','darkorange':'1','darkorchid':'1','darkred':'1','darksalmon':'1','darkseagreen':'1','darkslateblue':'1','darkslategray':'1','darkturquoise':'1','darkviolet':'1','deeppink':'1','deepskyblue':'1','dimgray':'1','dodgerblue':'1','firebrick':'1','floralwhite':'1','forestgreen':'1','fuchsia':'1','gainsboro':'1','ghostwhite':'1','gold':'1','goldenrod':'1','gray':'1','green':'1','greenyellow':'1','honeydew':'1','hotpink':'1','indianred':'1','indigo':'1','ivory':'1','khaki':'1','lavender':'1','lavenderblush':'1','lawngreen':'1','lemonchiffon':'1','lightblue':'1','lightcoral':'1','lightcyan':'1','lightgoldenrodyellow':'1','lightgrey':'1','lightgreen':'1','lightpink':'1','lightsalmon':'1','lightseagreen':'1','lightskyblue':'1','lightslategray':'1','lightsteelblue':'1','lightyellow':'1','lime':'1','limegreen':'1','linen':'1','magenta':'1','maroon':'1','mediumaquamarine':'1','mediumblue':'1','mediumorchid':'1','mediumpurple':'1','mediumseagreen':'1','mediumslateblue':'1','mediumspringgreen':'1','mediumturquoise':'1','mediumvioletred':'1','midnightblue':'1','mintcream':'1','mistyrose':'1','moccasin':'1','navajowhite':'1','navy':'1','oldlace':'1','olive':'1','olivedrab':'1','orange':'1','orangered':'1','orchid':'1','palegoldenrod':'1','palegreen':'1','paleturquoise':'1','palevioletred':'1','papayawhip':'1','peachpuff':'1','peru':'1','pink':'1','plum':'1','powderblue':'1','purple':'1','red':'1','rosybrown':'1','royalblue':'1','saddlebrown':'1','salmon':'1','sandybrown':'1','seagreen':'1','seashell':'1','sienna':'1','silver':'1','skyblue':'1','slateblue':'1','slategray':'1','snow':'1','springgreen':'1','steelblue':'1','tan':'1','teal':'1','thistle':'1','tomato':'1','turquoise':'1','violet':'1','wheat':'1','white':'1','whitesmoke':'1','yellow':'1','yellowgreen':'1'};
		this.getCodeName = function() { return 'Color'; }
		this.getDisplayName = function() { return 'color'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return true; }
		this.mustHaveArgument = function() { return true; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) {
			if(argument === null || argument === undefined) return false;
			if(BBCodeParser.isValidKey(browserColors, argument.toLowerCase()) ||
			   argument.match(/^#[\dabcdef]{3}$/i) != null ||
			   argument.match(/^#[\dabcdef]{6}$/i) != null) {
				return true;
			}
			if(BBCodeParser.isValidKey(settings, 'ColorAllowAdvFormats') && settings['ColorAllowAdvFormats'] &&
			  (argument.match(/^rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)$/i).length > 0 ||
			   argument.match(/^rgba\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*((0?\.\d+)|1|0)\s*\)$/i).length > 0 ||
			   argument.match(/^hsl\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}\s+%\)$/i).length > 0 ||
			   argument.match(/^hsla\(\s*\d{1,3}\s*,\s*\d{1,3}\s+%,\s*\d{1,3}\s+%,\s*((0?\.\d+)|1|0)\s*\)$/i).length > 0)) {
				return true;
			}
			return false;
		}
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) { 
			return '<span style="color: ' + PHPC.htmlspecialchars(argument) + '">';
		}
		this.close = function(settings, argument, closingCode) {
			return '</span>';
		}
	}
	HTMLColorBBCode.prototype = new BBCode;

	function HTMLFontBBCode() {
		this.getCodeName = function() { return 'Font'; }
		this.getDisplayName = function() { return 'font'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return true; }
		this.mustHaveArgument = function() { return true; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return argument !== null; }
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) { 
			return '<span style="font-family: \'' + PHPC.htmlspecialchars(argument) + '\'">';
		}
		this.close = function(settings, argument, closingCode) {
			return '</span>';
		}
	}
	HTMLFontBBCode.prototype = new BBCode;

	function HTMLLeftBBCode() {
		this.getCodeName = function() { return 'Left'; }
		this.getDisplayName = function() { return 'left'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return false; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return false; }
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			return (closingCode === null)? '<div style="display: block; text-align: left">' : '';
		}
		this.close = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			return (closingCode === null)? '</div>' : '';
		}
	}
	HTMLLeftBBCode.prototype = new BBCode;

	function HTMLCenterBBCode() {
		this.getCodeName = function() { return 'Center'; }
		this.getDisplayName = function() { return 'center'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return false; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return false; }
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			return (closingCode === null)? '<div style="display: block; text-align: center">' : '';
		}
		this.close = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			return (closingCode === null)? '</div>' : '';
		}
	}
	HTMLCenterBBCode.prototype = new BBCode;

	function HTMLRightBBCode() {
		this.getCodeName = function() { return 'Right'; }
		this.getDisplayName = function() { return 'right'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return false; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return false; }
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			return (closingCode === null)? '<div style="display: block; text-align: right">' : '';
		}
		this.close = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			return (closingCode === null)? '</div>' : '';
		}
	}
	HTMLRightBBCode.prototype = new BBCode;

	function HTMLQuoteBBCode() {
		this.getCodeName = function() { return 'Quote'; }
		this.getDisplayName = function() { return 'quote'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return true; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return true; }
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			if(closingCode === null) {
				var box  = '<div ';
				if(argument) box += 'class="' + PHPC.htmlspecialchars(settings['QuoteCSSClassName'].replace('{by}', argument)) + '"';
				box += 'style="display: block; margin-bottom: .5em; border: ' + PHPC.htmlspecialchars(settings['QuoteBorder']) + '; background-color: ' + PHPC.htmlspecialchars(settings['QuoteBackground']) + '">';
				box += '<div style="display: block; width: 100%; text-indent: .25em; border-bottom: ' + PHPC.htmlspecialchars(settings['QuoteBorder']) + '; background-color: ' + PHPC.htmlspecialchars(settings['QuoteTitleBackground']) + '">';
				box += 'QUOTE';
				if(argument) box += ' by ' + PHPC.htmlspecialchars(argument);
				box += '</div>';
				box += '<div style="overflow-x: auto; padding: .25em">';
				return box;
			}
		}
		this.close = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			return (closingCode === null)? '</div></div>' : '';
		}
	}
	HTMLQuoteBBCode.prototype = new BBCode;

	function HTMLCodeBBCode() {
		this.getCodeName = function() { return 'Code'; }
		this.getDisplayName = function() { return 'code'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return false; }
		this.canHaveArgument = function() { return true; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return true; }
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			if(closingCode === null) {
				var box  = '<div style="display: block; margin-bottom: .5em; border: ' + PHPC.htmlspecialchars(settings['CodeBorder']) + '; background-color: ' + PHPC.htmlspecialchars(settings['CodeBackground']) + '">';
				box += '<div style="display: block; width: 100%; text-indent: .25em; border-bottom: ' + PHPC.htmlspecialchars(settings['CodeBorder']) + '; background-color: ' + PHPC.htmlspecialchars(settings['CodeTitleBackground']) + '">';
				box += 'CODE';
				if(argument) box += ' (' + PHPC.htmlspecialchars(argument) + ')';
				box += '</div><pre ';
				if(argument) box += 'class="' + PHPC.htmlspecialchars(str_replace('{lang}', argument, settings['CodeCSSClassName'])) + '" ';
				box += 'style="overflow-x: auto; margin: 0; font-family: monospace; white-space: pre-wrap; padding: .25em">';
				return box;
			}
			return '';
		}
		this.close = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			return (closingCode === null)? '</pre></div>' : '';
		}
	}
	HTMLCodeBBCode.prototype = new BBCode;

	function HTMLCodeBoxBBCode() {
		this.getCodeName = function() { return 'Code Box'; }
		this.getDisplayName = function() { return 'codebox'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return false; }
		this.canHaveArgument = function() { return true; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return true; }
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			if(closingCode === null) {
				var box  = '<div style="display: block; margin-bottom: .5em; border: ' + PHPC.htmlspecialchars(settings['CodeBorder']) + '; background-color: ' + PHPC.htmlspecialchars(settings['CodeBackground']) + '">';
				box += '<div style="display: block; width: 100%; text-indent: .25em; border-bottom: ' + PHPC.htmlspecialchars(settings['CodeBorder']) + '; background-color: ' + PHPC.htmlspecialchars(settings['CodeTitleBackground']) + '">';
				box += 'CODE';
				if(argument) box += ' (' + PHPC.htmlspecialchars(argument) + ')';
				box += '</div><pre ';
				if(argument) box += 'class="' + PHPC.htmlspecialchars(str_replace('{lang}', argument, settings['CodeCSSClassName'])) + '" ';
				box += 'style="height: 29ex; overflow-y: auto; margin: 0; font-family: monospace; white-space: pre-wrap; padding: .25em">';
				return box;
			}
			return '';
		}
		this.close = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			return (closingCode === null)? '</pre></div>' : '';
		}
	}
	HTMLCodeBoxBBCode.prototype = new BBCode;

	function HTMLLinkBBCode() {
		this.getCodeName = function() { return 'Link'; }
		this.getDisplayName = function() { return 'url'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return true; }
		this.mustHaveArgument = function() { return true; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return true; }
		this.isValidParent = function(settings, parent) { return parent !== this.getDisplayName(); }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) {
			var decoration = (!BBCodeParser.isValidKey(settings, 'LinkUnderline') || settings['LinkUnderline'])? 'underline' : 'none';
			return '<a style="text-decoration: ' + decoration + '; color: ' + PHPC.htmlspecialchars(settings['LinkColor']) + '" href="' + PHPC.htmlspecialchars(argument) + '">';
		}
		this.close = function(settings, argument, closingCode) {
			return '</a>';
		}
	}
	HTMLLinkBBCode.prototype = new BBCode;

	function HTMLImageBBCode() {
		this.getCodeName = function() { return 'Image'; }
		this.getDisplayName = function() { return 'img'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return false; }
		this.canHaveArgument = function() { return true; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) {
			if(argument === null || argument === undefined) return true;

			var args = argument.split('x');
			if(args.length === 2) {

				var width = PHPC.floatval(args[0]);
				var height = PHPC.floatval(args[1]);
				return (width === Math.floor(width) && height === Math.floor(height));
			}
			return false;
		}
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			return (closingCode === null)? '<img src="' : '';
		}
		this.close = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			if(closingCode === null) {
				if(argument) {
					var args = argument.split('x');
					var width = PHPC.intval(args[0]);
					var height = PHPC.intval(args[1]);

					if(BBCodeParser.isValidKey(settings, 'ImageMaxWidth') && PHPC.intval(settings['ImageMaxWidth']) !== 0) {
						width = Math.min(width, PHPC.intval(settings['ImageMaxWidth']));
					}
					if(BBCodeParser.isValidKey(settings, 'ImageMaxHeight') && PHPC.intval(settings['ImageMaxHeight']) !== 0) {
						height = Math.min(height, PHPC.intval(settings['ImageMaxHeight']));
					}
					return '" alt="image" style="width: ' + width + '; height: ' + height + '"' + ((settings['XHTML'])? '/>' : '>');
				}
				return '" alt="image"' + ((settings['XHTML'])? '/>' : '>');
			}
			return '';
		}
	}
	HTMLImageBBCode.prototype = new BBCode;

	function HTMLUnorderedListBBCode() {
		var types = {
			'circle' : 'circle',
			'disk'   : 'disk',
			'square' : 'square'
		};
		this.getCodeName = function() { return 'Unordered List'; }
		this.getDisplayName = function() { return 'ul'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return true; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) {
			if(argument === null || argument === undefined) return true;
			return BBCodeParser.isValidKey(types, argument);
		}
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			if(closingCode === null) {
				var key = null;

				if(BBCodeParser.isValidKey(types, argument)) key = types[argument];
				if(!key && BBCodeParser.isValidKey(settings, 'UnorderedListDefaultType') && BBCodeParser.isValidKey(types, settings['UnorderedListDefaultType'])) {
					key = types[settings['UnorderedListDefaultType']];
				}
				if(!key) key = types['disk'];

				return '<ul style="list-style-type: ' + PHPC.htmlspecialchars(key) + '">';
			}
			return '';
		}
		this.close = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			return (closingCode === null)? '</ul>' : '';
		}
	}
	HTMLUnorderedListBBCode.prototype = new BBCode;


	function HTMLOrderedListBBCode() {
		var types = {
			'1'      : 'decimal',
			'a'      : 'lower-alpha',
			'A'      : 'upper-alpha',
			'i'      : 'lower-roman',
			'I'      : 'upper-roman'
		};
		this.getCodeName = function() { return 'Unordered List'; }
		this.getDisplayName = function() { return 'ol'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return true; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) {
			if(argument === null || argument === undefined) return true;
			return BBCodeParser.isValidKey(types, argument);
		}
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			if(closingCode === null) {
				var key = null;

				if(BBCodeParser.isValidKey(types, argument)) key = types[argument];
				if(!key && BBCodeParser.isValidKey(settings, 'OrderedListDefaultType') && BBCodeParser.isValidKey(types, settings['OrderedListDefaultType'])) {
					key = types[settings['OrderedListDefaultType']];
				}
				if(!key) key = types['1'];

				return '<ol style="list-style-type: ' + PHPC.htmlspecialchars(key) + '">';
			}
			return '';
		}
		this.close = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			return (closingCode === null)? '</ol>' : '';
		}
	}
	HTMLOrderedListBBCode.prototype = new BBCode;


	function HTMLListItemBBCode() {
		this.getCodeName = function() { return 'List Item'; }
		this.getDisplayName = function() { return 'li'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return false; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return false; }
		this.isValidParent = function(settings, parent) {
			return parent === 'ul' || parent === 'ol';
		}
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) { return '<li>'; }
		this.close = function(settings, argument, closingCode) { return '</li>'; }
	}
	HTMLListItemBBCode.prototype = new BBCode;

	function HTMLListBBCode() {
		var ul_types = {
			'circle' : 'circle',
			'disk'   : 'disk',
			'square' : 'square'
		};
		var ol_types = {
			'1'      : 'decimal',
			'a'      : 'lower-alpha',
			'A'      : 'upper-alpha',
			'i'      : 'lower-roman',
			'I'      : 'upper-roman'
		};
		this.getCodeName = function() { return 'List'; }
		this.getDisplayName = function() { return 'list'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return true; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return null; }
		this.getAutoCloseCodeOnClose = function() { return '*'; }
		this.isValidArgument = function(settings, argument) {
			if(argument === null || argument === undefined) return true;
			return BBCodeParser.isValidKey(ol_types, argument) ||
			       BBCodeParser.isValidKey(ul_types, argument);
		}
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			if(closingCode === null) {
				key = getType(settings, argument);
				return '<' + ((BBCodeParser.isValidKey(ol_types, key))? 'ol' : 'ul') + ' style="list-style-type: ' + PHPC.htmlspecialchars(argument) + '">';
			}
			return '';
		}
		this.close = function(settings, argument, closingCode) {
			if(closingCode === undefined) closingCode = null;
			if(closingCode === null) {
				var key = getType(settings, argument);
				return '</' + ((BBCodeParser.isValidKey(ol_types, key))? 'ol' : 'ul') + '>';
			}
			return '';
		}
		function getType(settings, argument) {
			var key = null;

			if(BBCodeParser.isValidKey(ul_types, argument)) {
				key = ul_types[argument];
			}
			if(!key && BBCodeParser.isValidKey(ol_types, argument)) {
				key = ol_types[argument];
			}
			if(!key && BBCodeParser.isValidKey(settings, 'ListDefaultType')) {
				key = ul_types[settings['ListDefaultType']];
			}
			if(!key && BBCodeParser.isValidKey(settings, 'ListDefaultType')) {
				key = ol_types[settings['ListDefaultType']];
			}
			if(!key) key = ul_types['disk'];

			return key;
		}
	}
	HTMLListBBCode.prototype = new BBCode;

	function HTMLStarBBCode() {
		this.getCodeName = function() { return 'Star'; }
		this.getDisplayName = function() { return '*'; }
		this.needsEnd = function() { return true; }
		this.canHaveCodeContent = function() { return true; }
		this.canHaveArgument = function() { return false; }
		this.mustHaveArgument = function() { return false; }
		this.getAutoCloseCodeOnOpen = function() { return '*'; }
		this.getAutoCloseCodeOnClose = function() { return null; }
		this.isValidArgument = function(settings, argument) { return false; }
		this.isValidParent = function(settings, parent) { return true; }
		this.escape = function(settings, content) { return PHPC.htmlspecialchars(content); }
		this.open = function(settings, argument, closingCode) { return '<li>'; }
		this.close = function(settings, argument, closingCode) { return '</li>'; }
	}
	HTMLStarBBCode.prototype = new BBCode;
