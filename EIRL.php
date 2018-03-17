<?php

/*
    WildPHP - a modular and easily extendable IRC bot written in PHP
    Copyright (C) 2015 WildPHP

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

namespace WildPHP\Modules\EIRL;

define('IRC_FONT_RED',    "\x0304");
define('IRC_FONT_GREEN',  "\x0303");
define('IRC_FONT_BLUE',   "\x0302");
define('IRC_FONT_PURPLE', "\x0306");
define('IRC_FONT_BROWN',  "\x0305");
define('IRC_FONT_PINK',   "\x0313");
define('IRC_FONT_ORANGE', "\x0307");
define('IRC_FONT_GREY',   "\x0314");

define('IRC_FONT_BOLD',   "\x02");
define('IRC_FONT_ITALIC', "\x1D");
define('IRC_FONT_UNDRLN', "\x1F");
define('IRC_FONT_RESET',  "\x0F");


use WildPHP\BaseModule;
use WildPHP\CoreModules\Connection\Connection;
use WildPHP\CoreModules\Connection\IrcDataObject;
use WildPHP\Validation;


// eZ80 Include Reverse Lookup
class EIRL extends BaseModule
{

    /**
     * @var IrcDataObject
     */
    private $irc_obj;

    private $lastTime = 0;

    private $fromAddr = [];
    private $fromName = [];

    private function initEquates()
    {
        $handle = fopen(__DIR__ . '/ti84pce.lab', 'r');
        if ($handle)
        {
            while (($line = fgets($handle)) !== false)
            {
                preg_match('/^(\S+)\s*=\s*\$(\w+)$/', $line, $matches);
                list(, $name, $addr) = $matches;

                $addr = hexdec($addr);

                $this->fromAddr[$addr][] = $name;
                $this->fromName[$name]   = $addr;
            }
            fclose($handle);
        }
        else
        {
            die('Could not open ti84pce.lab');
        }
    }

    public function setup()
    {
        $this->initEquates();

        $functions = [ 'rl', 'ascii' ];
        foreach ($functions as $func)
        {
            $this->getEventEmitter()->on('irc.command.' . $func, [$this, 'funcDispatcher']);
        }
    }

    public function funcDispatcher($command, $params, IrcDataObject $object)
    {
        $this->irc_obj = $object;

        if ($this->lastTime > time() - 2) {
            return;
        }

        $methodName = 'handler_' . $command;
        if (method_exists($this, $methodName)) {
            $this->lastTime = time();
            try {
                $this->$methodName($params);
            } catch (\Exception $e) {
                $this->writeMessage('Oops, something went wrong (exception caught) :(');
                echo $e->getMessage() . "\n";
            }
        }
    }

    /* adapted from http://stackoverflow.com/a/27444149/378298 */
    // code point to UTF-8 string
    private function unichr($i) {
        return iconv('UCS-4LE', 'UTF-8', pack('V', $i));
    }
    // UTF-8 string to code point
    private function uniord($s) {
        return json_encode(array_values(unpack('V*', iconv('UTF-8', 'UCS-4LE', $s))));
    }

    public function handler_ascii($params)
    {
        try
        {
            $input = trim(explode(' ', $params)[0]); // trim first word.
            if (empty($input)) {
                $this->writeMessage('Give me a char, a string, or a number');
                return;
            }
            $output = is_numeric($input) ? $this->unichr((int)$input) : (string)$this->uniord((string)$input);
            $this->writeMessage(empty($output) ? 'Wut? idk.' : $output);
        } catch (\Exception $e) {
            $this->writeMessage('Wut? (Exception caught).');
        }
    }


    /**
     * Returns an hex version of an address (prefix == '$'), or an empty string if it can't , and the decimal addr. (in array)
     * @param   string  $addr
     * @return  int|null
     */
    private function makeDecimalAddress($addr = '')
    {
        if (empty($addr)) {
            return null;
        }

        $addr = strtoupper($addr);

        if (($addr[0] === '$') || (substr($addr, 0, 2) === '0X') || (substr($addr, -1, 1) === 'h'))
        {
            if (strlen($addr) > 1 && $addr[0] === '$')
            {
                return hexdec(substr($addr, 1));
            }
            elseif (strlen($addr) > 2 && substr($addr, 0, 2) === '0X')
            {
                return hexdec(substr($addr, 2));
            }
            elseif (strlen($addr) > 1 && substr($addr, -1, 1) === 'H')
            {
                return hexdec(substr($addr, 0, -1));
            }
        } else {
            return (1 === preg_match('/[A-F]/', $addr)) ? hexdec($addr) : (int)$addr;
        }

        // can't deal with it...
        return null;
    }

    public function handler_rl($params)
    {
        try
        {
            $trimmedInput = trim(explode(' ', $params)[0]); // trim first word.

            $isAnAddr = (1 === preg_match('/^(0x|\$)?[0-9a-f]+h?$/i', $trimmedInput));

            $okInput = $trimmedInput;
            $hexAddr = '';

            if ($isAnAddr)
            {
                $okInput = $this->makeDecimalAddress($trimmedInput);
            }
            if (empty($okInput))
            {
                $this->writeMessage('Wut?');
                return;
            }

            if ($isAnAddr)
            {
                $hexAddr = '$' . strtoupper(dechex($okInput));
            }

            $betterInput = IRC_FONT_ORANGE . $trimmedInput . IRC_FONT_RESET . (($isAnAddr && $trimmedInput !== $hexAddr) ? " (== {$hexAddr})" : '');
            if ($isAnAddr)
            {
                if ($okInput > 0xFFFFFF)
                {
                    $this->writeMessage("Wut? That's too big.");
                    return;
                }

                if (isset($this->fromAddr[$okInput]))
                {
                    $thing = $this->fromAddr[$okInput];
                    $multiple = count($thing) > 1;
                    $this->writeMessage($betterInput . ' is ' . ( $multiple ? ('one of ' . IRC_FONT_BOLD . mb_strimwidth(json_encode($thing), 0, 250, '...')) : (IRC_FONT_BOLD . $thing[0]) ) . IRC_FONT_RESET );
                }
                else
                {
                    $bestAddrBefore = 0.5;
                    $bestAddrAfter  = 0xFFFFFF+1;
                    foreach ($this->fromAddr as $addr => $names)
                    {
                        if ($addr < $okInput)
                        {
                            if ($bestAddrBefore < $addr)
                            {
                                $bestAddrBefore = $addr;
                            }
                        } else {
                            if ($bestAddrAfter > $addr)
                            {
                                $bestAddrAfter = $addr;
                            }
                        }
                    }

                    $msgBefore = $msgAfter = '';
                    if ($bestAddrBefore !== 0.5)
                    {
                        $thing = $this->fromAddr[$bestAddrBefore];
                        $multiple = count($thing) > 1;
                        $thing = $multiple ? ('one of ' . IRC_FONT_BOLD . mb_strimwidth(json_encode($thing), 0, 250, '...')) : (IRC_FONT_BOLD . $thing[0]);
                        $offsetBefore = $okInput - $bestAddrBefore;
                        $msgBefore = $thing . '+' . ($offsetBefore > 0xF ? '0x' : '') . dechex($offsetBefore) . IRC_FONT_RESET;
                    }
                    if ($bestAddrAfter !== 0xFFFFFF+1)
                    {
                        $thing = $this->fromAddr[$bestAddrAfter];
                        $multiple = count($thing) > 1;
                        $thing = $multiple ? ('one of ' . IRC_FONT_BOLD . mb_strimwidth(json_encode($thing), 0, 250, '...')) : (IRC_FONT_BOLD . $thing[0]);
                        $offsetAfter = $bestAddrAfter - $okInput;
                        $msgAfter  = $thing . '-' . ($offsetAfter > 0xF ? '0x' : '')  . dechex($offsetAfter)  . IRC_FONT_RESET;
                    }
                    if ($msgBefore !== '' || $msgAfter !== '')
                    {
                        $msg = $betterInput . ' could be ';
                        if ($msgBefore !== '')
                        {
                            $msg .= $msgBefore;
                            if ($msgAfter !== '') {
                                $msg .= ' or ' . $msgAfter;
                            }
                        } else {
                            $msg .= $msgAfter;
                        }
                        $this->writeMessage($msg);
                    } else {
                        $this->writeMessage("Wut? Couldn't find stuff with offsets. That's not normal...");
                    }
                }
            } else {
                if (isset($this->fromName[$okInput]))
                {
                    $this->writeMessage($betterInput . ' is ' . IRC_FONT_BOLD . '$' . strtoupper(dechex($this->fromName[$okInput])) . IRC_FONT_RESET);
                } else {
                    // Loop to check lowercase version
                    foreach ($this->fromName as $name => &$addr)
                    {
                        if (strtolower($name) === strtolower($okInput))
                        {
                            $this->writeMessage(IRC_FONT_ORANGE . $name . IRC_FONT_RESET . ' is ' . IRC_FONT_BOLD . '$' . strtoupper(dechex($addr)) . IRC_FONT_RESET . ' (different name case!)');
                            return;
                        }
                    }
                    unset($addr);

                    // If not found, look for the closest ones
                    $matches = [];
                    foreach ($this->fromName as $name => $addr) {
                        $matches[] = [ levenshtein($okInput, $name), $name, $addr ];
                    }
                    if (!empty($matches))
                    {
                        usort($matches, function($a, $b){ return $a[0] - $b[0]; });
                        $matches = array_slice($matches, 0, 3);

                        $str = '';
                        foreach ($matches as $match) {
                            $str .= IRC_FONT_ORANGE . $match[1] . IRC_FONT_RESET . ' == ' . IRC_FONT_BOLD . '$' . strtoupper(dechex($match[2])) . IRC_FONT_RESET . ', ';
                        }
                        $str = trim($str, ', ');

                        $this->writeMessage("Wut? idk but the 3 closest matches are: {$str}.");
                    } else {
                        // Shouldn't happen
                        $this->writeMessage('Wut? idk.');
                    }

                    return;
                }
            }

        } catch (\Exception $e) {
            $this->writeMessage('Oops, something went wrong (exception caught) :(');
        }
    }

    /********************************************/

    private function writeMessage($msg)
    {
        $channel = $this->irc_obj->getTargets()[0];
        $connection = $this->getModule('Connection'); /** @var Connection $connection */
        $connection->write($connection->getGenerator()->ircPrivmsg($channel, $msg));
    }

    private function writeResults($results, $limit = 3)
    {
        $countResults = count($results);

        $stopCount = 0;
        if ($countResults > 0)
        {
            foreach ($results as $result)
            {
                $msg = $result;

                $this->writeMessage($msg);

                $stopCount++;
                if ($stopCount >= $limit)
                    return;
            }
        } else {
            $this->writeMessage('Nothing to output :(');
        }
    }
}

