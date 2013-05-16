""" -- https://bitbucket.org/AMcBain/bb-code-parser
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
    OR SERVICES LOSS OF USE, DATA, OR PROFITS OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY
    OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

   --

    While this software is released "as is", I don't mind getting bug reports.



   Most of the supported code specifications were aquired from here: http:#www.bbcode.org/reference.php

   Due to the way this parser/formatter is designed, content of a code is cannot be relied on to be passed
   to the escape function on a code instance in between the calling of the open and close functions. So
   certain things otherwise workable might not be (such as using the content of a link as the argument if
   no argument was given).

   This parser/formatter does not support calling out to anonymous functions (callbacks) when a code with-
   out an implementation is encountered. The parser/formatter would have to accept callbacks for all
   methods available on BBCode (plus an extra parameter for the code name). This is not in the plan to be
   added as a feature. Maybe an adventerous person could attempt this.



    Using the BBCodeParser:
    Note any of the inputs shown here can be skipped by sending None instead:
    ex:  BBCodeParser(None, settings)

    # Replace all defined codes with default settings
    parser = BBCodeParser()
    output = parser.format(finput)

    # Specify allowed codes
    parser = BBCodeParser({
        allowed_codes: ['b', 'i', 'u']
    })
    output = parser.format(finput)

    # Replace the implementation for 'Bold'. This is a noop as written, but shows how
    # to replace built-ins with custom implementations if so wished.
    parser = BBCodeParser({
        codes: {
            'b': HTMLBoldBBCode()
        }
    })
    output = parser.format(finput)

    # Override default settings. Custom settings can be specified to pass along info
    # to custom BB-code implementations but will be ignored by the default included
    # implementations.
    parser = new BBCodeParser({
        settings : {
            'LinkColor' : 'green',
            'CustomSetting1' : 3
        }
    })
    output = parser.format(finput)
    
    # The above are just simple examples. Multiple properties can be set and combined
    # together when instantiating a parser.
"""

from abc import ABCMeta
from abc import abstractmethod
import re
import cgi
import math


class BBCode(metaclass=ABCMeta):
    """ Standard interface to be implemented by all "BB-Codes" """

    @abstractmethod
    def get_code_name(self):
        """ Name to be displayed, ex: Bold """

    @abstractmethod
    def get_display_name(self):
        """
        Name of the code as written. ex: b
        Display names *must not* start with /
        """

    @abstractmethod
    def needs_end(self):
        """ Whether or not this code has an end marker.
        Codes without an end marker should implement the open method, and leave the close method empty """

    @abstractmethod
    def can_have_code_content(self):
        """ Demotes whether a code's content should be parsed for other codes
        Codes such as a [code][/code] block might not want their content parsed for other codes """

    @abstractmethod
    def can_have_argument(self):
        """ Whether or not this code can have an argument """

    @abstractmethod
    def must_have_argument(self):
        """ Whether or not this code must have an argument
        For consistency, a code which cannot have an argument should return false here """

    @abstractmethod
    def get_auto_close_code_on_open(self):
        """
        Denotes whether or not the parser should generate a closing code if the returned opening code is already in effect
        This is called before a new code of a type is opened. Return None to indicate that no code should be auto closed
        The code returned should be equivalent to the "display name" of the code to be closed, ex: 'b' not 'Bold'
        Confusing? ex: '[*]foo, bar [*]baz!' (if auto close code is '*') generates '[*]foo, bar[/*][*]baz!'
                   An "opening" [*] was recorded, so when it hit the second [*], it inserted a closing [/*] first
        """

    @abstractmethod
    def get_auto_close_code_on_close(self):
        """ See documentation for get_auto_close_code_on_open """

    @abstractmethod
    def is_valid_argument(self, settings, argument=None):
        """ Whether or not the given argument is valid
        Codes which do not take an argument should return false and those which accept any value should return true """

    @abstractmethod
    def is_valid_parent(self, settings, parent=None):
        """
        Whether or not the actual display name of a code is a valid parent for this code
        The "actual display name" is 'ul' or 'ol', not "Unordered List", etc.
        If the code isn't nested, 'GLOBAL' will be passed instead
        """

    @abstractmethod
    def escape(self, settings, content):
        """ Escape content that will eventually be sent to the format function. Take care not to escape the content again inside the format function """

    @abstractmethod
    def open(self, settings, argument=None, closing_code=None):
        """
        Returns a statement indicating the opening of something which contains content
        (whatever that is in the output format/language returned)
        argument is the part after the equals in some BB-Codes, ex: [url=http:#example.org]...[/url]
        closing_code is used when allowOverlappingCodes is true and contains the code being closed
                    (this is because all open codes are closed then reopened after the closing_code is closed)
        """

    @abstractmethod
    def close(self, settings, argument=None, closing_code=None):
        """
        Returns a statement indicating the closing of something which contains content
        (whatever that is in the output format/language returned)
        argument is the part after the equals in some BB-Codes, ex: [url=http:#example.org]...[/url]
        closing_code is used when allowOverlappingCodes is true and cotnains the code being closed
                    (this is because all open codes are closed then reopened after the closing_code is closed)
                    None is sent for to the code represented by closing_code (it cannot 'force close' itself)
        """


class BBCodeParser:
    """
    Class for the BB-Code Parser.
    Each parser is immutable, each instance's settings, codes, etc, are "final" after the parser is created.
    """

    def _setup_default_codes(self):
        """
        Mapped Array with all the default implementations of bb_codes.
        It is not advised this be edited directly as this will affect all other calls.
        Instead, pass a Mapped Array of only the codes to be overridden to the BBCodeParser_replace function.
        """
        self.bb_codes = {
             'GLOBAL': HTMLGlobalBBCode(),
                  'b': HTMLBoldBBCode(),
                  'i': HTMLItalicBBCode(),
                  'u': HTMLUnderlineBBCode(),
                  's': HTMLStrikeThroughBBCode(),
               'font': HTMLFontBBCode(),
               'size': HTMLFontSizeBBCode(),
              'color': HTMLColorBBCode(),
               'left': HTMLLeftBBCode(),
             'center': HTMLCenterBBCode(),
              'right': HTMLLeftBBCode(),
              'quote': HTMLQuoteBBCode(),
               'code': HTMLCodeBBCode(),
            'codebox': HTMLCodeBoxBBCode(),
                'url': HTMLLinkBBCode(),
                'img': HTMLImageBBCode(),
                 'ul': HTMLUnorderedListBBCode(),
                 'ol': HTMLOrderedListBBCode(),
                 'li': HTMLListItemBBCode(),
               'list': HTMLListBBCode(),
                  '*': HTMLStarBBCode()
        }

    def __init__(self, options=None):
        """
        Sets up the BB-Code parser with the given settings.
        If None is passed for allowed codes, all are allowed. If no settings are passed, defaults are used.
        These parameters are supplimentary and overrides, that is, they are in addition to the defaults
        already included, but they will override an default if found.

        These options are passed in via an object. Just don't define those which you want to use the default.

        allowed_codes is an array of "display names" (b, i, ...) that are allowed to be parsed and formatted
                     in the output. If None is passed, all default codes are allowed.
           Default: allow all defaults

        settings is a mapped array of settings which various formatter implementations may use to control output.
           Default: use built in default settings

        codes is a mapped array of "display names" to implementations of BBCode which are used to format output.
              Any codes with the same name as a default will replace the default implementation. If you also
              specify allowed_codes, don't forget to include these.
           Default: no supplementary codes

        replaceDefaults indicates whether the previous codes map should be used in place of all the defaults
                        instead of supplementing it. If this is set to true, and no GLOBAL code implementation is
                        provided in the codes map, a default one will be provided that just returns content given
                        to it unescaped.
           Default: False

        all_or_nothing refers to what happens when an invalid code is found. If true, it stops returns the input.
                     If false, it keeps on going (output may not display as expected).
                     Codes which are not allowed or codes for which no formatter cannot be found are not invalid.
           Default: True

        handle_overlapping_codes tells the parser to properly (forcefully) handle overlapping codes.
                               This is done by closing open tags which overlap, then reopening them after
                               the closed one. This will only work when all_or_nothing is false.
           Default: False

        escape_content_output tells the parser whether or not it should escape the contents of bb_codes in the output.
                            Content is any text not directely related to a BBCode itself. [b]this is content[/b]
           Default: True

        code_start_symbol is the symbol denoting the start of a code (default is [ for easy compatability)
           Default: '['

        code_end_symbol is the symbol denoting the end of a code (default is ] for easy compatability with BB-Code)
           Default: ']'
        """

        self.bb_code_count = 0
        self._setup_default_codes()

        # The allowed codes (set up in the constructor)
        self.allowed_codes = []

        # Mapped properties which can be used by BBCode implementations to affect output.
        # It is not advised this be edited directly as this will affect all other calls.
        # Instead, pass a Mapped Array of only the properties to be overridden to the BBCodeParser_replace function.
        self.settings = {
                               'XHTML': False,
                        'FontSizeUnit': 'px',
                         'FontSizeMax': 48,               # Set to None to allow any font-size
                'ColorAllowAdvFormats': False,            # Whether the rgb[a], hsl[a] color formats should be accepted
                'QuoteTitleBackground': '#e4eaf2',
                         'QuoteBorder': '1px solid gray',
                     'QuoteBackground': 'white',
                   'QuoteCSSClassName': 'quotebox-{by}',  # {by} is the quote parameter ex: [quote=Waldo], {by} = Waldo
                 'CodeTitleBackground': '#ffc29c',
                          'CodeBorder': '1px solid gray',
                      'CodeBackground': 'white',
                    'CodeCSSClassName': 'codebox-{lang}', # {lang} is the code parameter ex: [code=PHP], {lang} = php
                       'LinkUnderline': True,
                           'LinkColor': 'blue'#,
        #               'ImageWidthMax': 640,              # Uncomment these to tell the BB-Code parser to use them
        #              'ImageHeightMax': 480,              # The default is to allow any size image
        #    'UnorderedListDefaultType': 'disk',           # Uncomment these to tell the BB-Code parser to use this
        #      'OrderedListDefaultType': '1',              # default type if the given one is invalid **
        #             'ListDefaultType': 'disk'            # ...
        }

        # ** Note that this affects whether a tag is printed out "as is" if a bad argument is given.
        # It may not affect those tags which can take "" or nothing as their argument
        # (they may assign a relevant default themselves).

        # See the constructor comment for details
        self.all_or_nothing = True
        self.handle_overlapping_codes = False
        self.escape_content_output = True
        self.code_start_symbol = '['
        self.code_end_symbol = ']'

        if options:
            if BBCodeParser.is_valid_key(options, 'all_or_nothing'):
                self.all_or_nothing = bool(options['all_or_nothing'])

            if BBCodeParser.is_valid_key(options, 'handle_overlapping_codes'):
                self.handle_overlapping_codes = bool(options['handle_overlapping_codes'])

            if BBCodeParser.is_valid_key(options, 'escape_content_output'):
                self.escape_content_output = bool(options['escape_content_output'])

            if BBCodeParser.is_valid_key(options, 'code_start_symbol'):
                self.code_start_symbol = options['code_start_symbol']

            if BBCodeParser.is_valid_key(options, 'code_end_symbol'):
                self.code_end_symbol = options['code_end_symbol']

            # Copy settings
            if BBCodeParser.is_valid_key(options, 'settings'):
                for key, value in options['settings'].items():
                    self.settings[key] = value + ''

            # Copy passed code implementations
            if BBCodeParser.is_valid_key(options, 'codes'):

                if BBCodeParser.is_valid_key(options, 'replaceDefaults') and options['replaceDefaults']:
                    self.bb_codes = options['codes']
                else:
                    for key, value in options['codes'].items():
                        if isinstance(value, BBCode):
                            self.bb_codes[key] = value

        self.bb_code_count = len(self.bb_codes)

        # If no global bb-code implementation, provide a default one.
        if BBCodeParser.is_valid_key(self.bb_codes, 'GLOBAL') or not isinstance(self.bb_codes['GLOBAL'], BBCode):

            # This should not affect the bb-code count as if it is the only bb-code, the effect is
            # the same as if no bb-codes were allowed / supplied.
            self.bb_codes['GLOBAL'] = DefaultGlobalBBCode()

        if options and BBCodeParser.is_valid_key(options, 'allowed_codes') and isinstance(options['allowed_codes'], list):
            self.allowed_codes = options['allowed_codes'][0:]
        else:
            for key in self.bb_codes.keys():
                self.allowed_codes.append(key)

    def format(self, finput, options=None):
        """
        Parses and replaces allowed bb_codes with the settings given when this parser was created
        all_or_nothing, handleOverlapping, and escape_content_output codes can be overridden per call
        """

        all_or_nothing = self.all_or_nothing
        handle_overlapping_codes = self.handle_overlapping_codes
        escape_content_output = self.escape_content_output

        if options:
            if BBCodeParser.is_valid_key(options, 'all_or_nothing'):
                all_or_nothing = bool(options['all_or_nothing'])

            if BBCodeParser.is_valid_key(options, 'handle_overlapping_codes'):
                handle_overlapping_codes = bool(options['handle_overlapping_codes'])

            if BBCodeParser.is_valid_key(options, 'escape_content_output'):
                escape_content_output = bool(options['escape_content_output'])

        # Why bother parsing if there's no codes to find?
        if self.bb_code_count > 0 and len(self.allowed_codes) > 0:
            return self._replace(finput, self.allowed_codes, self.settings, self.bb_codes, all_or_nothing, handle_overlapping_codes, escape_content_output, self.code_start_symbol, self.code_end_symbol)

        return finput

    def _replace(self, finput, allowed_codes, settings, codes, all_or_nothing, handle_overlapping_codes, escape_content_output, code_start_symbol, code_end_symbol):
        """ Internal method for finding codes in input and matching them to the appropriate implementations for formatting. """
        output = ''

        # If no brackets, just dump it back out (don't spend time parsing it)
        if finput.rfind(code_start_symbol) != -1 and finput.rfind(code_end_symbol) != -1:
            queue = [] # queue of codes and content
            stack = [] # stack of open codes

            # Iterate over input, finding start symbols
            tokenizer = MultiTokenizer(finput)
            while tokenizer.has_next_token(code_start_symbol):

                before = tokenizer.next_token(code_start_symbol)
                code = tokenizer.next_token(code_end_symbol)

                # If "valid" parse further
                if code != '':

                    # Store content before code
                    if before != '':
                        queue.append(Token(Token.CONTENT, before))

                    # Check if the tokenizer ran out of input trying to find the end of a code caused by a stray codeEndSymbol.
                    if tokenizer.is_exhausted() and input[len(input) - len(codeEndSymbol):] != codeEndSymbol:
                        queue.append(new Token(Token.CONTENT, codeStartSymbol + code))
                        continue

                    # Parse differently depending on whether or not there's an argument
                    equals = code.rfind('=')
                    if equals != -1:
                        code_display_name = code[0:equals]
                        code_argument = code[equals+1:]
                    else:
                        code_display_name = code
                        code_argument = None

                    # End codes versus start codes
                    if code[0:1] == '/':
                        code_no_slash = code_display_name[1:]
                        auto_close_code = codes[code_no_slash].get_auto_close_code_on_close()

                        # Handle auto closing codes
                        if (BBCodeParser.is_valid_key(codes, code_no_slash) and auto_close_code and
                                BBCodeParser.is_valid_key(codes, auto_close_code) and auto_close_code in stack):

                            self._array_remove(stack, auto_close_code, True)
                            queue.append(Token(Token.CODE_END, '/' + auto_close_code))

                        queue.append(Token(Token.CODE_END, code_display_name))
                        code_display_name = code_no_slash
                    else:
                        auto_close_code = codes[code_display_name].get_auto_close_code_on_open()

                        # Handle auto closing codes
                        if (BBCodeParser.is_valid_key(codes, code_display_name) and auto_close_code and
                                BBCodeParser.is_valid_key(codes, auto_close_code) and auto_close_code in stack):

                            self._array_remove(stack, auto_close_code, True)
                            queue.append(Token(Token.CODE_END, '/' + auto_close_code))

                        queue.append(Token(Token.CODE_START, code_display_name, code_argument))
                        stack.append(code_display_name)

                    # Check for codes with no implementation and codes which aren't allowed
                    if not BBCodeParser.is_valid_key(codes, code_display_name):
                        queue[len(queue) - 1].status = Token.NOIMPLFOUND

                    elif not code_display_name in allowed_codes:
                        queue[len(queue) - 1].status = Token.NOTALLOWED

                elif code == '':
                    queue.append(Token(Token.CONTENT, before + '[]'))

            # Get any text after the last end symbol
            last_bits = tokenizer.position_to_end_token()
            if last_bits != '':
                queue.append(Token(Token.CONTENT, last_bits))

            # Find/mark all valid start/end code pairs
            count = len(queue)
            for i in range(0, count):
                token = queue[i]

                # Handle undetermined and valid codes
                if token.status != Token.NOIMPLFOUND and token.status != Token.NOTALLOWED:

                    # Handle start and end codes
                    if token.ttype == Token.CODE_START:

                        # Start codes which don't need an end are valid
                        if not codes[token.content].needs_end():
                            token.status = Token.VALID

                    elif token.ttype == Token.CODE_END:
                        content = token.content[1:]

                        # Ending codes for items which don't need an end are technically invalid, but since
                        # the start code is valid (and self-contained) we'll turn them into regular content
                        if not codes[content].needs_end():
                            token.ttype = Token.CONTENT
                            token.status = Token.VALID
                        else:

                            # Try our best to handle overlapping codes (they are a real PITA)
                            if handle_overlapping_codes:
                                start = self._find_start_code_of_type(queue, content, i)
                            else:
                                start = self._find_start_code_with_status(queue, Token.UNDETERMINED, i)

                            # Handle valid end codes, mark others invalid
                            if start == -1 or queue[start].content != content:
                                token.status = Token.INVALID
                            else:
                                token.status = Token.VALID
                                token.matches = start
                                queue[start].status = Token.VALID
                                queue[start].matches = i

                # If all or nothing, just return the input (as we found 1 invalid code)
                if all_or_nothing and token.status == Token.INVALID:
                    return finput

            # Empty the stack
            stack = []

            # Final loop to print out all the open/close tags as appropriate
            for i in range(0, count):
                token = queue[i]

                # Escape content tokens via their parent's escaping function
                if token.ttype == Token.CONTENT:
                    parent = self._find_start_code_with_status(queue, Token.VALID, i)

                    if not escape_content_output:
                        output += token.content

                    elif parent == -1 or not BBCodeParser.is_valid_key(codes, queue[parent].content):
                        output += codes['GLOBAL'].escape(settings, token.content)
                    else:
                        output += codes[queue[parent].content].escape(settings, token.content)

                # Handle start codes
                elif token.ttype == Token.CODE_START:
                    parent = None

                    # If undetermined or currently valid, validate against various codes rules
                    if token.status != Token.NOIMPLFOUND and token.status != Token.NOTALLOWED:
                        parent = self._find_parent_start_code(queue, i)

                        if ((token.status == Token.UNDETERMINED and codes[token.content].needs_end()) or
                                (codes[token.content].can_have_argument() and not codes[token.content].is_valid_argument(settings, token.argument)) or
                                (not codes[token.content].can_have_argument() and token.argument) or
                                (codes[token.content].must_have_argument() and not token.argument) or
                                (parent != -1 and not codes[queue[parent].content].can_have_code_content())):

                            token.status = Token.INVALID
                            # Both tokens in the pair should be marked
                            if token.status:
                                queue[token.matches].status = Token.INVALID

                            # all_or_nothing, return input
                            if all_or_nothing:
                                return finput

                        parent = 'GLOBAL' if parent == -1 else queue[parent].content

                    # Check the parent code too ... some codes are only used within other codes
                    if token.status == Token.VALID and not codes[token.content].is_valid_parent(settings, parent):

                        if token.matches:
                            queue[token.matches].status = Token.INVALID
                        token.status = Token.INVALID

                        if allOrNothing:
                            return finput

                    if token.status == Token.VALID:
                        output += codes[token.content].open(settings, token.argument)

                        # Store all open codes
                        if handle_overlapping_codes:
                            stack.append(token)
                    elif token.argument is not None:
                        output += code_start_symbol.token.content + '=' + token.argument.code_end_symbol
                    else:
                        output += code_start_symbol.token.content.code_end_symbol

                # Handle end codes
                elif token.ttype == Token.CODE_END:

                    if token.status == Token.VALID:
                        content = token.content[1:]

                        # Remove the closing code, close all open codes
                        if handle_overlapping_codes:
                            scount = count(stack)

                            # Codes must be closed in the same order they were opened
                            for j in range(scount - 1, -1, -1):
                                jtoken = stack[j]
                                output += codes[jtoken.content].close(settings, jtoken.argument, None if jtoken.content == content else content)

                            # Removes matching open code
                            self._array_remove(stack, queue[token.matches], True)
                        else:

                            # Close the current code
                            output += codes[content].close(settings, token.argument)

                        # Now reopen all remaing codes
                        if handle_overlapping_codes:
                            scount = count(stack)

                            for j in range(0, scount):
                                jtoken = stack[j]
                                output += codes[jtoken.content].open(settings, jtoken.argument, None if jtoken.content == content else content)
                    else:
                        output += code_start_symbol.token.content.code_end_symbol
        else:
            output += finput if not escape_content_output else codes['GLOBAL'].escape(settings, finput)

        return output

    @classmethod
    def _find_start_code_with_status(cls, queue, status, position):
        """ Finds the closest parent with a certain status to the given position, working backwards """

        for i in range(position - 1, -1, -1):
            if queue[i].ttype == Token.CODE_START and queue[i].status == status:
                return i
        return -1

    @classmethod
    def _find_start_code_of_type(cls, queue, content, position):
        """ Finds the closest valid parent with a certain content to the given position, working backwards """

        for i in range(position - 1, -1, -1):
            if (queue[i].ttype == Token.CODE_START and queue[i].status == Token.UNDETERMINED and
                    queue[i].content == content):
                return i
        return -1

    @classmethod
    def _find_parent_start_code(cls, queue, position):
        """ Find the parent start-code of another code """

        for i in range(position - 1, -1, -1):
            if (queue[i].ttype == Token.CODE_START and queue[i].status == Token.VALID and
                    queue[i].matches > position):
                return i
        return -1

    @classmethod
    def _array_remove(cls, stack, match, first=False):
        """ Removes the given value from an array """

        i = 0
        count = len(stack)
        while i < count:

            if stack[i] == match:
                del stack[i:i+1]

                if first:
                    return
                count -= 1
                i -= 1

    @staticmethod
    def is_valid_key(array, key):
        """ Whether or not a key in an array is valid or not (is set, and is not None) """
        return key in array and array[key] != None


class MultiTokenizer(object):
    """
    A "multiple token" tokenizer.
    This will not return the text between the last found token and the end of the string,
    as no token will match "end of string". There is no special "end of string" token to
    match against either, as with an arbitrary token to find, how does one know they are
    "one from the end"?
    """

    def __init__(self, tinput='', position=0):
        self.input = tinput + ''
        self.length = len(self.input)
        self.position = int(position)

    def is_exhausted():
        return self.position >= self.length

    def position_to_end_token():
        return self.input[self.position:]

    def has_next_token(self, delimiter=' '):
        """
        Returns whether there is another token delimited by the given delimiter or the
        default delimiter if not given.
        """
        return self.input.find(delimiter, min(self.length, self.position)) != -1

    def next_token(self, delimiter=' '):
        """
        Returns the next token using the given delimiter or the default delimiter if one
        is not given. Returns None if the position of the tokenizer is past the end of
        the input.
        """

        if self.position >= self.length:
            return None

        index = self.input.find(delimiter, self.position)
        if index == -1:
            index = self.length

        result = self.input[self.position:index]
        self.position = index + 1

        return result

    def reset(self):
        """ Resets the position of the tokenizer to the start of the input. """
        self.position = 0


class Token(object):
    """ Class representing a BB-Code-oriented token """

    NONE = 'NONE'
    CODE_START = 'CODE_START'
    CODE_END = 'CODE_END'
    CONTENT = 'CONTENT'

    VALID = 'VALID'
    INVALID = 'INVALID'
    NOTALLOWED = 'NOTALLOWED'
    NOIMPLFOUND = 'NOIMPLFOUND'
    UNDETERMINED = 'UNDETERMINED'

    def __init__(self, ttype, content, argument=None):
        self.ttype = ttype
        self.content = content
        self.status = self.VALID if self.ttype == self.CONTENT else self.UNDETERMINED
        self.argument = argument
        self.matches = None # matching start/end code index


class DefaultGlobalBBCode(BBCode):
    """ Default implementation of a top-level "global" bb-code. """

    def get_code_name(self):
        return 'GLOBAL'

    def get_display_name(self):
        return 'GLOBAL'

    def needs_end(self):
        return False

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return False

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return False

    def is_valid_parent(self, settings, parent=None):
        return False

    def escape(self, settings, content):
        return content
    def open(self, settings, argument=None, closing_code=None):
        return ''

    def close(self, settings, argument=None, closing_code=None):
        return ''


##
# HTML implementations
##

class HTMLGlobalBBCode(BBCode):
    """ A top-level "global" bb-code implementation that is HTML aware. """

    def get_code_name(self):
        return 'GLOBAL'

    def get_display_name(self):
        return 'GLOBAL'

    def needs_end(self):
        return False

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return False

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return False

    def is_valid_parent(self, settings, parent=None):
        return False

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):
        return ''

    def close(self, settings, argument=None, closing_code=None):
        return ''


class HTMLBoldBBCode(BBCode):
    """ Bold bb-code that outputs HTML. """

    def get_code_name(self):
        return 'Bold'

    def get_display_name(self):
        return 'b'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return False

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return False

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):
        return '<b>'

    def close(self, settings, argument=None, closing_code=None):
        return '</b>'


class HTMLItalicBBCode(BBCode):
    """ Italic bb-code that outputs HTML. """

    def get_code_name(self):
        return 'Italic'

    def get_display_name(self):
        return 'i'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return False

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return False

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):
        return '<i>'

    def close(self, settings, argument=None, closing_code=None):
        return '</i>'


class HTMLUnderlineBBCode(BBCode):
    """ Underline bb-code that outputs HTML. """

    def get_code_name(self):
        return 'Underline'

    def get_display_name(self):
        return 'u'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return False

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return False

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):
        return '<u>'

    def close(self, settings, argument=None, closing_code=None):
        return '</u>'


class HTMLStrikeThroughBBCode(BBCode):
    """ Strike-through bb-code that outputs HTML. """

    def get_code_name(self):
        return 'StrikeThrough'

    def get_display_name(self):
        return 's'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return False

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return False

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):
        return '<s>'

    def close(self, settings, argument=None, closing_code=None):
        return '</s>'


class HTMLFontSizeBBCode(BBCode):
    """ Font size bb-code that outputs HTML. """

    def get_code_name(self):
        return 'Font Size'

    def get_display_name(self):
        return 'size'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return True

    def must_have_argument(self):
        return True

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def is_valid_parent(self, settings, parent=None):
        return True

    def is_valid_argument(self, settings, argument=None):

        if (not BBCodeParser.is_valid_key(settings, 'FontSizeMax') or
                (BBCodeParser.is_valid_key(settings, 'FontSizeMax') and int(settings['FontSizeMax']) <= 0)):
            return int(argument) > 0

        return int(argument) > 0 and int(argument) <= int(settings['FontSizeMax'])

    def open(self, settings, argument=None, closing_code=None):
        return '<span style="font-size: ' + int(argument) + cgi.escape(settings['FontSizeUnit'], True) + '">'

    def close(self, settings, argument=None, closing_code=None):
        return '</span>'


class HTMLColorBBCode(BBCode):
    """ Color bb-code that outputs HTML. """

    browserColors = {'aliceblue':'1', 'antiquewhite':'1', 'aqua':'1', 'aquamarine':'1', 'azure':'1', 'beige':'1', 'bisque':'1', 'black':'1', 'blanchedalmond':'1', 'blue':'1', 'blueviolet':'1', 'brown':'1', 'burlywood':'1', 'cadetblue':'1', 'chartreuse':'1', 'chocolate':'1', 'coral':'1', 'cornflowerblue':'1', 'cornsilk':'1', 'crimson':'1', 'cyan':'1', 'darkblue':'1', 'darkcyan':'1', 'darkgoldenrod':'1', 'darkgray':'1', 'darkgreen':'1', 'darkkhaki':'1', 'darkmagenta':'1', 'darkolivegreen':'1', 'darkorange':'1', 'darkorchid':'1', 'darkred':'1', 'darksalmon':'1', 'darkseagreen':'1', 'darkslateblue':'1', 'darkslategray':'1', 'darkturquoise':'1', 'darkviolet':'1', 'deeppink':'1', 'deepskyblue':'1', 'dimgray':'1', 'dodgerblue':'1', 'firebrick':'1', 'floralwhite':'1', 'forestgreen':'1', 'fuchsia':'1', 'gainsboro':'1', 'ghostwhite':'1', 'gold':'1', 'goldenrod':'1', 'gray':'1', 'green':'1', 'greenyellow':'1', 'honeydew':'1', 'hotpink':'1', 'indianred':'1', 'indigo':'1', 'ivory':'1', 'khaki':'1', 'lavender':'1', 'lavenderblush':'1', 'lawngreen':'1', 'lemonchiffon':'1', 'lightblue':'1', 'lightcoral':'1', 'lightcyan':'1', 'lightgoldenrodyellow':'1', 'lightgrey':'1', 'lightgreen':'1', 'lightpink':'1', 'lightsalmon':'1', 'lightseagreen':'1', 'lightskyblue':'1', 'lightslategray':'1', 'lightsteelblue':'1', 'lightyellow':'1', 'lime':'1', 'limegreen':'1', 'linen':'1', 'magenta':'1', 'maroon':'1', 'mediumaquamarine':'1', 'mediumblue':'1', 'mediumorchid':'1', 'mediumpurple':'1', 'mediumseagreen':'1', 'mediumslateblue':'1', 'mediumspringgreen':'1', 'mediumturquoise':'1', 'mediumvioletred':'1', 'midnightblue':'1', 'mintcream':'1', 'mistyrose':'1', 'moccasin':'1', 'navajowhite':'1', 'navy':'1', 'oldlace':'1', 'olive':'1', 'olivedrab':'1', 'orange':'1', 'orangered':'1', 'orchid':'1', 'palegoldenrod':'1', 'palegreen':'1', 'paleturquoise':'1', 'palevioletred':'1', 'papayawhip':'1', 'peachpuff':'1', 'peru':'1', 'pink':'1', 'plum':'1', 'powderblue':'1', 'purple':'1', 'red':'1', 'rosybrown':'1', 'royalblue':'1', 'saddlebrown':'1', 'salmon':'1', 'sandybrown':'1', 'seagreen':'1', 'seashell':'1', 'sienna':'1', 'silver':'1', 'skyblue':'1', 'slateblue':'1', 'slategray':'1', 'snow':'1', 'springgreen':'1', 'steelblue':'1', 'tan':'1', 'teal':'1', 'thistle':'1', 'tomato':'1', 'turquoise':'1', 'violet':'1', 'wheat':'1', 'white':'1', 'whitesmoke':'1', 'yellow':'1', 'yellowgreen':'1'}

    def get_code_name(self):
        return 'Color'

    def get_display_name(self):
        return 'color'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return True

    def must_have_argument(self):
        return True

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):

        if argument is None:
            return False

        if (BBCodeParser.is_valid_key(self.browserColors, argument.lower()) or
                re.match(r'^#[\dabcdef]{3}$', argument, re.IGNORECASE) is not None or
                re.match(r'^#[\dabcdef]{6}$', argument, re.IGNORECASE) is not None):
            return True

        if (BBCodeParser.is_valid_key(settings, 'ColorAllowAdvFormats') and settings['ColorAllowAdvFormats'] and
                (re.match(r'^rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)$', argument, re.IGNORECASE) is not None or
                re.match(r'^rgba\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*((0?\.\d+)|1|0)\s*\)$', argument, re.IGNORECASE) is not None or
                re.match(r'^hsl\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}\s+%\)$', argument, re.IGNORECASE) is not None or
                re.match(r'^hsla\(\s*\d{1,3}\s*,\s*\d{1,3}\s+%,\s*\d{1,3}\s+%,\s*((0?\.\d+)|1|0)\s*\)$', argument, re.IGNORECASE) is not None)):
            return True

        return False

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):
        return '<span style="color: ' + cgi.escape(argument, True) + '">'

    def close(self, settings, argument=None, closing_code=None):
        return '</span>'


class HTMLFontBBCode(BBCode):
    """ Font bb-code that outputs HTML. """

    def get_code_name(self):
        return 'Font'

    def get_display_name(self):
        return 'font'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return True

    def must_have_argument(self):
        return True

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return argument is not None
    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):
        return '<span style="font-family: \'' + cgi.escape(argument, True) + '\'">'

    def close(self, settings, argument=None, closing_code=None):
        return '</span>'


class HTMLLeftBBCode(BBCode):
    """ Left bb-code that outputs HTML. """

    def get_code_name(self):
        return 'Left'

    def get_display_name(self):
        return 'left'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return False

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return False

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):
        return '<div style="display: block text-align: left">' if closing_code else ''

    def close(self, settings, argument=None, closing_code=None):
        return '</div>' if closing_code else ''


class HTMLCenterBBCode(BBCode):
    """ Center bb-code that outputs HTML. """

    def get_code_name(self):
        return 'Center'

    def get_display_name(self):
        return 'center'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return False

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return False

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):
        return '<div style="display: block text-align: center">' if closing_code else ''

    def close(self, settings, argument=None, closing_code=None):
        return '</div>' if closing_code else ''


class HTMLRightBBCode(BBCode):
    """ Right bb-code that outputs HTML. """

    def get_code_name(self):
        return 'Right'

    def get_display_name(self):
        return 'right'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return False

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return False

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):
        return '<div style="display: block text-align: right">' if closing_code else ''

    def close(self, settings, argument=None, closing_code=None):
        return '</div>' if closing_code else ''


class HTMLQuoteBBCode(BBCode):
    """ Quote bb-code that outputs HTML. """

    def get_code_name(self):
        return 'Quote'

    def get_display_name(self):
        return 'quote'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return True

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return True

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):

        if closing_code is None:
            box = '<div '

            if argument:
                box += 'class="' + cgi.escape(settings['QuoteCSSClassName'].replace('{by}', argument), True) + '" '

            box += style="display: block margin-bottom: .5em border: ' + cgi.escape(settings['QuoteBorder'], True) + ' background-color: ' + cgi.escape(settings['QuoteBackground'], True) + '">'
            box += '<div style="display: block width: 100% text-indent: .25em border-bottom: ' + cgi.escape(settings['QuoteBorder'], True) + ' background-color: ' + cgi.escape(settings['QuoteTitleBackground'], True) + '">'
            box += 'QUOTE'

            if argument:
                box += ' by ' + cgi.escape(argument, True)

            box += '</div>'
            box += '<div style="overflow-x: auto padding: .25em">'
            return box

    def close(self, settings, argument=None, closing_code=None):
        return '</div></div>' if closing_code else ''


class HTMLCodeBBCode(BBCode):
    """ Code bb-code that outputs HTML. """

    def get_code_name(self):
        return 'Code'

    def get_display_name(self):
        return 'code'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return False

    def can_have_argument(self):
        return True

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return True

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):

        if closing_code is None:
            box = '<div style="display: block margin-bottom: .5em border: ' + cgi.escape(settings['CodeBorder'], True) + ' background-color: ' + cgi.escape(settings['CodeBackground'], True) + '">'
            box += '<div style="display: block width: 100% text-indent: .25em border-bottom: ' + cgi.escape(settings['CodeBorder'], True) + ' background-color: ' + cgi.escape(settings['CodeTitleBackground'], True) + '">'
            box += 'CODE'

            if argument:
                box += ' (' + cgi.escape(argument, True) + ')'

            box += '</div><pre '

            if argument:
                box += 'class="' + cgi.escape(settings['CodeCSSClassName'].replace('{lang}', argument), True) + '" '

            box += 'style="overflow-x: auto margin: 0 font-family: monospace white-space: pre-wrap padding: .25em">'
            return box

        return ''

    def close(self, settings, argument=None, closing_code=None):
        return '</pre></div>' if closing_code else ''


class HTMLCodeBoxBBCode(BBCode):
    """ Code box bb-code that outputs HTML. """

    def get_code_name(self):
        return 'Code Box'

    def get_display_name(self):
        return 'codebox'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return False

    def can_have_argument(self):
        return True

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return True

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):

        if closing_code is None:
            box = '<div style="display: block margin-bottom: .5em border: ' + cgi.escape(settings['CodeBorder'], True) + ' background-color: ' + cgi.escape(settings['CodeBackground'], True) + '">'
            box += '<div style="display: block width: 100% text-indent: .25em border-bottom: ' + cgi.escape(settings['CodeBorder'], True) + ' background-color: ' + cgi.escape(settings['CodeTitleBackground'], True) + '">'
            box += 'CODE'

            if argument:
                box += ' (' + cgi.escape(argument, True) + ')'

            box += '</div><pre '

            if argument:
                box += 'class="' + cgi.escape(settings['CodeCSSClassName'].replace('{lang}', argument), True) + '" '

            box += 'style="height: 29ex overflow-y: auto margin: 0 font-family: monospace white-space: pre-wrap padding: .25em">'
            return box

        return ''

    def close(self, settings, argument=None, closing_code=None):
        return '</pre></div>' if closing_code else ''


class HTMLLinkBBCode(BBCode):
    """ Link bb-code that outputs HTML. """

    def get_code_name(self):
        return 'Link'

    def get_display_name(self):
        return 'url'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return True

    def must_have_argument(self):
        return True

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return True

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):
        decoration = 'underline' if (not BBCodeParser.is_valid_key(settings, 'LinkUnderline') or settings['LinkUnderline']) else 'none'
        return '<a style="text-decoration: ' + decoration + ' color: ' + cgi.escape(settings['LinkColor'], True) + '" href="' + cgi.escape(argument, True) + '">'

    def close(self, settings, argument=None, closing_code=None):
        return '</a>'


class HTMLImageBBCode(BBCode):
    """ Image bb-code that outputs HTML. """

    def get_code_name(self):
        return 'Image'

    def get_display_name(self):
        return 'img'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return False

    def can_have_argument(self):
        return True

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):

        if argument is None:
            return True

        args = argument.split('x')
        return len(args) == 2 and float(args[0]) == math.floor(float(args[0])) and float(args[1]) == math.floor(float(args[1]))

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):
        return '<img src="' if closing_code else ''

    def close(self, settings, argument=None, closing_code=None):

        if closing_code is None:
            if argument is not None:
                args = argument.split('x')
                width = int(args[0])
                height = int(args[1])

                if BBCodeParser.is_valid_key(settings, 'ImageMaxWidth') and int(settings['ImageMaxWidth']) != 0:
                    width = min(width, int(settings['ImageMaxWidth']))

                if BBCodeParser.is_valid_key(settings, 'ImageMaxHeight') and int(settings['ImageMaxHeight']) != 0:
                    height = min(height, int(settings['ImageMaxHeight']))

                return '" alt="image" style="width: ' + width + ' height: ' + height + '"' + ('/>' if settings['XHTML'] else '>')

            return '" alt="image"' + ('/>' if settings['XHTML'] else '>')

        return ''


class HTMLUnorderedListBBCode(BBCode):
    """ Unordered list bb-code that outputs HTML. """

    types = {
        'circle': 'circle',
          'disk': 'disk',
        'square': 'square'
    }

    def get_code_name(self):
        return 'Unordered List'

    def get_display_name(self):
        return 'ul'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return True

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):

        if argument is None:
            return True

        return BBCodeParser.is_valid_key(self.types, argument)

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):

        if closing_code is None:
            key = None

            if BBCodeParser.is_valid_key(self.types, argument):
                key = self.types[argument]

            if not key and BBCodeParser.is_valid_key(settings, 'UnorderedListDefaultType') and BBCodeParser.is_valid_key(self.types, settings['UnorderedListDefaultType']):
                key = self.types[settings['UnorderedListDefaultType']]

            if not key:
                key = self.types['disk']

            return '<ul style="list-style-type: ' + cgi.escape(key, True) + '">'

        return ''

    def close(self, settings, argument=None, closing_code=None):
        return '</ul>' if closing_code else ''


class HTMLOrderedListBBCode(BBCode):
    """ Ordered list bb-code that outputs HTML. """

    types = {
        '1': 'decimal',
        'a': 'lower-alpha',
        'A': 'upper-alpha',
        'i': 'lower-roman',
        'I': 'upper-roman'
    }

    def get_code_name(self):
        return 'Unordered List'

    def get_display_name(self):
        return 'ol'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return True

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):

        if argument is None:
            return True

        return BBCodeParser.is_valid_key(self.types, argument)

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):

        if closing_code is None:
            key = None

            if BBCodeParser.is_valid_key(self.types, argument):
                key = self.types[argument]

            if not key and BBCodeParser.is_valid_key(settings, 'OrderedListDefaultType') and BBCodeParser.is_valid_key(self.types, settings['OrderedListDefaultType']):
                key = self.types[settings['OrderedListDefaultType']]

            if not key:
                key = self.types['1']

            return '<ol style="list-style-type: ' + cgi.escape(key, True) + '">'

        return ''

    def close(self, settings, argument=None, closing_code=None):
        return '</ol>' if closing_code else ''


class HTMLListItemBBCode(BBCode):
    """ List item bb-code that outputs HTML. """

    def get_code_name(self):
        return 'List Item'

    def get_display_name(self):
        return 'li'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return False

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return False

    def is_valid_parent(self, settings, parent=None):
        return parent == 'ul' or parent == 'ol'

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):
        return '<li>'

    def close(self, settings, argument=None, closing_code=None):
        return '</li>'


class HTMLListBBCode(BBCode):
    """ List bb-code that outputs HTML. """

    ul_types = {
        'circle': 'circle',
          'disk': 'disk',
        'square': 'square'
    }

    ol_types = {
        '1': 'decimal',
        'a': 'lower-alpha',
        'A': 'upper-alpha',
        'i': 'lower-roman',
        'I': 'upper-roman'
    }

    def get_code_name(self):
        return 'List'

    def get_display_name(self):
        return 'list'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return True

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return None

    def get_auto_close_code_on_close(self):
        return '*'

    def is_valid_argument(self, settings, argument=None):

        if argument is None:
            return True

        return (BBCodeParser.is_valid_key(self.ol_types, argument) or
                BBCodeParser.is_valid_key(self.ul_types, argument))

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):

        if closing_code is None:
            key = self._get_type(settings, argument)
            ttype = 'ol' if BBCodeParser.is_valid_key(self.ol_types, key) else 'ul'
            return '<' + ttype + ' style="list-style-type: ' + cgi.escape(argument, True) + '">'

        return ''

    def close(self, settings, argument=None, closing_code=None):

        if closing_code is None:
            key = self._get_type(settings, argument)
            ttype = 'ol' if BBCodeParser.is_valid_key(self.ol_types, key) else 'ul'
            return '</' + ttype + '>'

        return ''

    def _get_type(self, settings, argument):
        """ Returns the appropriate list tag name based on the argument. """
        key = None

        if BBCodeParser.is_valid_key(self.ul_types, argument):
            key = self.ul_types[argument]

        if not key and BBCodeParser.is_valid_key(self.ol_types, argument):
            key = self.ol_types[argument]

        if not key and BBCodeParser.is_valid_key(settings, 'ListDefaultType'):
            key = self.ul_types[settings['ListDefaultType']]

        if not key and BBCodeParser.is_valid_key(settings, 'ListDefaultType'):
            key = self.ol_types[settings['ListDefaultType']]

        if not key:
            key = self.ul_types['disk']

        return key


class HTMLStarBBCode(BBCode):
    """ Star (list item) bb-code that outputs HTML. """

    def get_code_name(self):
        return 'Star'

    def get_display_name(self):
        return '*'

    def needs_end(self):
        return True

    def can_have_code_content(self):
        return True

    def can_have_argument(self):
        return False

    def must_have_argument(self):
        return False

    def get_auto_close_code_on_open(self):
        return '*'

    def get_auto_close_code_on_close(self):
        return None

    def is_valid_argument(self, settings, argument=None):
        return False

    def is_valid_parent(self, settings, parent=None):
        return True

    def escape(self, settings, content):
        return cgi.escape(content, True)

    def open(self, settings, argument=None, closing_code=None):
        return '<li>'

    def close(self, settings, argument=None, closing_code=None):
        return '</li>'

