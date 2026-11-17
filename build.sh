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
<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="&pluginURL;" min="7.4.0" icon="icon-fan" launch="Settings/PWMFanControl">

<CHANGES>
$(tr -d '\r' < "$HERE/CHANGES.md")
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

echo "Built $OUT (version $VERSION)"
