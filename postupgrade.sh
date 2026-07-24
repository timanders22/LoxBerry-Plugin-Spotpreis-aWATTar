#!/bin/bash
# Spotpreis aWATTar - postupgrade: Konfiguration + Log wiederherstellen
ARGV1=$1
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-spotpreis}"
BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
[ -f "$ARGV1/spot.json" ] && cp -p "$ARGV1/spot.json" "$BASE/config/plugins/$PFOLDER/spot.json"
[ -f "$ARGV1/spot.log" ] && cp -p "$ARGV1/spot.log" "$BASE/log/plugins/$PFOLDER/spot.log"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/spot.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF"
    fi
fi
exit 0
