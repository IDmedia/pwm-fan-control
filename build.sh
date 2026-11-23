#!/bin/bash
# Builds pwm.fan.control.plg from source/.
# All files are embedded in the .plg, so the single .plg file is all you need to install.
#
# Usage: ./build.sh [version]          (default version: today's date, e.g. 2026.11.16)
# Set PLUGIN_URL to the raw URL the .plg will be served from, to enable update checks.

set -euo pipefail

NAME="pwm.fan.control"
HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="$HERE/source"
OUT="$HERE/$NAME.plg"
VERSION="${1:-$(date +%Y.%m.%d)}"
AUTHOR="${AUTHOR:-IDmedia}"
PLUGIN_URL="${PLUGIN_URL:-https://raw.githubusercontent.com/IDmedia/pwm-fan-control/main/$NAME.plg}"

# Prints a file wrapped in CDATA, with Windows line endings removed
cdata() {
    if grep -q ']]>' "$1"; then
        echo "Error: $1 contains ']]>' and cannot be embedded." >&2
        exit 1
    fi
    printf '<![CDATA['
    tr -d '\r' < "$1"
    printf ']]>'
}

# Prints a file as XML text (& < > escaped), with Windows line endings removed
xmltext() {
    tr -d '\r' < "$1" | sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g'
}

# Fails the build when the .plg is not valid XML (Unraid only says "xml parse error" when installing)
check_xml() {
    local py="" p
    if command -v xmllint > /dev/null; then
        xmllint --noout "$1" && return
    else
        for p in python3 python; do "$p" -c pass > /dev/null 2>&1 && { py="$p"; break; }; done
        if [ -z "$py" ]; then echo "Warning: no xmllint or python, $1 was not checked." >&2; return; fi
        "$py" -I -c 'import sys, xml.etree.ElementTree as ET; ET.parse(sys.argv[1])' "$1" && return
    fi
    echo "Error: $1 is not valid XML." >&2
    exit 1
}

{
cat << EOF
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name      "$NAME">
<!ENTITY author    "$AUTHOR">
<!ENTITY version   "$VERSION">
<!ENTITY pluginURL "$PLUGIN_URL">
<!ENTITY emhttp    "/usr/local/emhttp/plugins/&name;">
]>
<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="&pluginURL;" min="7.4.0" support="https://github.com/IDmedia/pwm-fan-control/issues" icon="icon-fan" launch="Settings/PWMFanControl">

<CHANGES>
$(xmltext "$HERE/CHANGES.md")
</CHANGES>

<!-- Remove files from a previous version -->
<FILE Run="/bin/bash">
<INLINE>
rm -rf /usr/local/emhttp/plugins/$NAME
</INLINE>
</FILE>

EOF

(cd "$SRC/emhttp" && find . -type f | sort) | while read -r rel; do
    rel="${rel#./}"
    printf '<FILE Name="&emhttp;/%s">\n<INLINE>' "$rel"
    cdata "$SRC/emhttp/$rel"
    printf '</INLINE>\n</FILE>\n\n'
done

printf '<!-- Install -->\n<FILE Run="/bin/bash">\n<INLINE>'
cdata "$SRC/install.sh"
printf '</INLINE>\n</FILE>\n\n'

printf '<!-- Remove -->\n<FILE Run="/bin/bash" Method="remove">\n<INLINE>'
cdata "$SRC/remove.sh"
printf '</INLINE>\n</FILE>\n\n'

echo '</PLUGIN>'
} > "$OUT"

check_xml "$OUT"
echo "Built $OUT (version $VERSION)"
