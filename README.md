# eirl_bot_irc
eZ80 (CE) includes/equates reverse lookup - irc bot module for wildphp

To get the `ti84pce.lab` file:
```sh
wget --no-verbose 'http://wikiti.brandonw.net/index.php?title=84PCE:OS:Include_File&action=raw' --output-document=- | sed -E '/^\[\[.*\]\]$/d;s|</?pre>||' > ti84pce.inc
spasm -E -A -L -O ti84pce.inc
```
