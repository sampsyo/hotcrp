export VERSION=2.61

# check that schema.sql and updateschema.php agree on schema version
updatenum=`grep 'settings.*allowPaperOption.*=' src/updateschema.php | tail -n 1 | sed 's/.*= *//;s/;.*//'`
schemanum=`grep 'allowPaperOption' Code/schema.sql | sed 's/.*, *//;s/).*//'`
if [ "$updatenum" != "$schemanum" ]; then
    echo "error: allowPaperOption schema number in Code/schema.sql ($schemanum)" 1>&2
    echo "error: differs from number in src/updateschema.php ($updatenum)" 1>&2
    exit 1
fi

# check that HOTCRP_VERSION is up to date -- unless first argument is -n
versionnum=`grep 'HOTCRP_VERSION' Code/header.inc | head -n 1 | sed 's/.*, "//;s/".*//'`
if [ "$versionnum" != "$VERSION" -a "$1" != "-n" ]; then
    echo "error: HOTCRP_VERSION in Code/header.inc ($versionnum)" 1>&2
    echo "error: differs from current version ($VERSION)" 1>&2
    exit 1
fi

mkdistdir () {
    crpd=hotcrp-$VERSION
    rm -rf $crpd
    mkdir $crpd

    while read f; do
	if [ -n "$f" ]; then
	    d=`echo "$f" | sed 's/[^\/]*$//'`
	    [ -n "$d" -a ! -d "$crpd/$d" ] && mkdir "$crpd/$d"
	    if [ -f "$f" ]; then
		ln "$f" "$crpd/$f"
	    else
		cp -r "$f" "$crpd/$d"
	    fi
	fi
    done

    export COPY_EXTENDED_ATTRIBUTES_DISABLE=true COPYFILE_DISABLE=true
    tar --exclude='.DS_Store' --exclude='._*' -czf $crpd.tar.gz $crpd
    rm -rf $crpd
}

mkdistdir <<EOF

.htaccess
LICENSE
NEWS
README.md
account.php
assign.php
autoassign.php
bulkassign.php
cacheable.php
checkupdates.php
comment.php
contacts.php
deadlines.php
doc.php
help.php
index.php
log.php
mail.php
manualassign.php
mergeaccounts.php
offline.php
paper.php
profile.php
resetpassword.php
review.php
reviewprefs.php
scorehelp.php
script.js
search.php
sessionvar.php
settings.php
style.css
supersleight.js
users.php

lib/.htaccess
lib/backupdb.sh
lib/cleanxhtml.php
lib/countries.php
lib/createdb.sh
lib/csv.php
lib/dbhelper.sh
lib/documenthelper.php
lib/ht.php
lib/ldaplogin.php
lib/login.php
lib/message.php
lib/mimetype.php
lib/qobject.php
lib/restoredb.sh
lib/runsql.sh
lib/tagger.php
lib/text.php
lib/unicodehelper.php
lib/xlsx.php

src/.htaccess
src/assigners.php
src/baselist.php
src/checkformat.php
src/commentview.php
src/conference.php
src/conflict.php
src/contact.php
src/contactlist.php
src/formula.php
src/helpers.php
src/hotcrpdocument.php
src/mailer.php
src/meetingtracker.php
src/messages.csv
src/paperactions.php
src/papercolumn.php
src/paperinfo.php
src/paperlist.php
src/paperoption.php
src/papersearch.php
src/paperstatus.php
src/papertable.php
src/rank.php
src/review.php
src/reviewformlibrary.json
src/reviewsetform.php
src/reviewtable.php
src/updateschema.php

Code/.htaccess
Code/banal
Code/distoptions.inc
Code/header.inc
Code/mailtemplate.inc
Code/sample.pdf
Code/schema.sql
Code/updateschema.sql

extra/hotcrp.vim

images/.htaccess
images/_.gif
images/GenChart.php
images/allreviews24.png
images/asprite.gif
images/ass-3.png
images/ass-2.png
images/ass-1.png
images/ass0.gif
images/ass1.gif
images/ass1n.gif
images/ass2.gif
images/ass2n.gif
images/ass3.gif
images/ass3n.gif
images/ass4.gif
images/ass4n.gif
images/assign18.png
images/assign24.png
images/bendulft.png
images/check.png
images/checksum12.png
images/comment24.png
images/cross.png
images/edit.png
images/edit18.png
images/edit24.png
images/exassignone.png
images/exsearchaction.png
images/extagsnone.png
images/extagssearch.png
images/extagsset.png
images/extagvotehover.png
images/generic.png
images/genericf.png
images/generic24.png
images/genericf24.png
images/headgrad.png
images/homegrad.png
images/info45.png
images/next.png
images/override24.png
images/pageresultsex.png
images/pdf.png
images/pdff.png
images/pdf24.png
images/pdff24.png
images/postscript.png
images/postscriptf.png
images/postscript24.png
images/postscriptf24.png
images/prev.png
images/quicksearchex.png
images/review18.png
images/review24.png
images/sortdown.png
images/sortup.png
images/sprite.png
images/stophand45.png
images/tag_blue_grey.png
images/tag_blue_purple.png
images/tag_green_blue.png
images/tag_green_grey.png
images/tag_green_purple.png
images/tag_orange_blue.png
images/tag_orange_green.png
images/tag_orange_grey.png
images/tag_orange_purple.png
images/tag_orange_yellow.png
images/tag_purple_grey.png
images/tag_red_blue.png
images/tag_red_green.png
images/tag_red_grey.png
images/tag_red_orange.png
images/tag_red_purple.png
images/tag_red_yellow.png
images/tag_yellow_blue.png
images/tag_yellow_green.png
images/tag_yellow_grey.png
images/tag_yellow_purple.png
images/timestamp12.png
images/txt.png
images/txt24.png
images/view18.png
images/view24.png
images/viewas.png

EOF
