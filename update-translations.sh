#!/bin/sh
TEMPLATE=af_img_phash.pot

xgettext -k__ -kT_sprintf -L PHP -o $TEMPLATE *.php
xgettext -k__ -L Java -j -o $TEMPLATE *.js

update_lang() {
	if [ -f $1.po ]; then
		msgmerge --no-wrap --width 1 -U $1.po $TEMPLATE
		msgfmt --statistics $1.po -o $1.mo
	else
		echo "Usage: $0 [-p|<basename>]"
	fi
}

LANGS=`find locale -name 'af_img_phash.po'`

for lang in $LANGS; do
	echo Updating $lang...
	PO_BASENAME=`echo $lang | sed s/.po//`
	update_lang $PO_BASENAME
done
