#!/bin/bash
# Spotpreis aWATTar - preupgrade: Konfiguration + Log sichern
ARGV1=$1
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-spotpreis}"
BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$ARGV1" 2>/dev/null
cp -p "$BASE/config/plugins/$PFOLDER/spot.json" "$ARGV1/spot.json" 2>/dev/null
cp -p "$BASE/log/plugins/$PFOLDER/spot.log" "$ARGV1/spot.log" 2>/dev/null
exit 0
