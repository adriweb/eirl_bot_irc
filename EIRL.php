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
use WildPHP\CoreModules\Connection\IrcDataObject;

// eZ80 Include Reverse Lookup
class EIRL extends BaseModule
{
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

                if (!isset($this->fromAddr[$addr]))
                {
                    $this->fromAddr[$addr] = [];
                }
                array_push($this->fromAddr[$addr], $name);
                $this->fromName[$name] = $addr;
            }
            fclose($handle);
        }
        else
        {
            die("Could not open ti84pce.lab");
        }
    }

	public function setup()
	{
        $this->initEquates();

        $functions = [ 'rl', 'whatis', 'whats', 'watis', 'wats' ];
		foreach ($functions as $func)
		{
			$this->getEventEmitter()->on('irc.command.' . $func, [$this, 'reverse_lookup']);
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

        if ((substr($addr, 0, 1) === '$') || (substr($addr, 0, 2) === '0X') || (substr($addr, -1, 1) === 'h'))
        {
            if (strlen($addr) > 1 && substr($addr, 0, 1) === '$')
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

    public function reverse_lookup($command, $params, IrcDataObject $object)
    {
        try
        {
            $trimmedInput = trim(explode(' ', $params)[0]); // trim first word.

            $isAnAddr = (1 === preg_match('/^(0x|\$)?[0-9a-f]+h?$/i', $trimmedInput));
            $isHexAddr = ($isAnAddr && (1 === preg_match('/[A-F]/', $trimmedInput)));

            $okInput = $trimmedInput;
            $hexAddr = '';

            if ($isAnAddr)
            {
                $okInput = $this->makeDecimalAddress($trimmedInput);
            }
            if ($okInput === null)
            {
                $this->writeMessage($object, "Wut?");
                return;
            }

            if ($isAnAddr)
            {
                $hexAddr = '$' . strtoupper(dechex($okInput));
            }

            $betterInput = IRC_FONT_ORANGE . $trimmedInput . IRC_FONT_RESET . (($isAnAddr && !$isHexAddr && strlen($hexAddr) > 2) ? " (== {$hexAddr})" : '');

            if ($isAnAddr)
            {
                if (isset($this->fromAddr[$okInput]))
                {
                    $thing = $this->fromAddr[$okInput];
                    $multiple = count($thing) > 1;
                    $this->writeMessage($object, $betterInput . ' is ' . ( $multiple ? ('one of ' . IRC_FONT_BOLD . mb_strimwidth(json_encode($thing), 0, 250, "...")) : (IRC_FONT_BOLD . $thing[0]) )
                                                                       . IRC_FONT_RESET );
                } else {
                    $msgBefore = $msgAfter = '';
                    $errBefore = $errAfter = false;
                    $offsetBefore = $offsetAfter = 1;
                    while (!isset($this->fromAddr[$okInput - $offsetBefore]))
                    {
                        $offsetBefore++;
                        if ($okInput - $offsetBefore < 1)
                        {
                            $errBefore = true;
                            break;
                        }
                    }
                    while (!isset($this->fromAddr[$okInput + $offsetAfter]))
                    {
                        $offsetAfter++;
                        if ($okInput + $offsetAfter > 0xFFFFFF)
                        {
                            $errAfter = true;
                            break;
                        }
                    }
                    if (!$errBefore)
                    {
                        $msgBefore = IRC_FONT_BOLD . $this->fromAddr[$okInput - $offsetBefore][0] . '+' . ($offsetBefore > 0xF ? '0x' : '') . dechex($offsetBefore) . IRC_FONT_RESET;
                    }
                    if (!$errAfter)
                    {
                        $msgAfter = IRC_FONT_BOLD . $this->fromAddr[$okInput + $offsetAfter][0] . '-' . ($offsetAfter > 0xF ? '0x' : '') . dechex($offsetAfter) . IRC_FONT_RESET;
                    }
                    if ($msgBefore !== '' || $msgAfter !== '')
                    {
                        $msg = $betterInput . ' could be ';
                        if ($msgBefore !== '')
                        {
                            $msg .= $msgBefore;
                            if ($msgAfter !== '') {
                                $msg .= ' or ';
                            }
                        }
                        if ($msgAfter !== '') {
                            $msg .= $msgAfter;
                        }
                        $this->writeMessage($object, $msg);
                    } else {
                        $this->writeMessage($object, "Wut? Couldn't find stuff with offsets. That's not normal...");
                    }
                }
            } else {
                if (isset($this->fromName[$okInput]))
                {
                    $this->writeMessage($object, $betterInput . ' is ' . IRC_FONT_BOLD . '$' . strtoupper(dechex($this->fromName[$okInput])) . IRC_FONT_RESET);
                } else {
                    $this->writeMessage($object, "Wut? idk.");
                    return;
                }
            }

        } catch (\Exception $e) {
            $this->writeMessage($object, "Oops, something went wrong (exception caught) :(");
        }
    }

    private function writeMessage(IrcDataObject $object, $msg)
    {
        $channel = $object->getTargets()[0];
        $connection = $this->getModule('Connection');
        $connection->write($connection->getGenerator()->ircPrivmsg($channel, $msg));
    }
}
