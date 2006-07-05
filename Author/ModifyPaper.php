<?php 
include('../Code/confHeader.inc');
$Conf->connect();
$_SESSION["Me"] -> goIfInvalid("../index.php");
$_SESSION["Me"] -> goIfNotAuthor("../index.php");
$Conf->goIfInvalidActivity("updatePaperSubmission", "../index.php");
$paperId = cvtint($_REQUEST["paperId"]);
if ($paperId <= 0)
    $Me->goAlert("../", "Invalid paper ID.");
include('PaperForm.inc');
?>

<html>
<?php  $Conf->header("Modify Paper #$paperId for $Conf->shortName") ?>

<body>
<?php 
if (!IsSet($paperId) ) {
  $Conf -> errorMsg("You didn't specify a paper to modify.");
  exit;
}

if ( ! $_SESSION["Me"] -> amPaperAuthor($paperId, $Conf) ) {
  $Conf -> errorMsg("Only the submitting paper author can modify the "
		    . "paper information.");
  exit;
}


$query = "SELECT acknowledged, withdrawn  FROM Paper " 
  . " WHERE Paper.paperId=$paperId";

$result = $Conf->qe($query);
$row = $result->fetchRow();
$finalized=$row[0];
$withdrawn=$row[1];

if ($withdrawn) {
  $Conf->infoMsg("This paper has been WITHDRAWN "
		 . " -- you can't do anything here");
  exit;
}

if ($finalized) {
  $Conf->infoMsg("This paper has been FINALIZED "
		 . " -- you can't do anything here");
  exit;
}

//
// Process any updates
//
if (IsSet($_REQUEST["submit"])) {
  if (!IsSet($_REQUEST["title"]) || !IsSet($_REQUEST["abstract"]) || !IsSet($_REQUEST["authorInfo"]) || !IsSet($_REQUEST["collaborators"]) ) {
    $Conf->errorMsg("One or more of the required fields is not set. "
		    . "Use this form or press BACK and correct this. ");
    exit();
  }

  if (sizeof($_REQUEST["topics"]) == 0) {
    if ( $Conf->thereAreTopics()) {
      $Conf->errorMsg("You must specify at least one topic. "
		      . "Use this form or press BACK and correct this. ");
      exit();
    }
  } 

  $query="update Paper set title='" . sqlq($_REQUEST['title']) . "', "
      . "abstract='" . sqlq($_REQUEST['abstract']) . "', "
      . "collaborators='" . sqlq($_REQUEST['collaborators']) . "', "
      . "authorInformation='" . sqlq($_REQUEST['authorInfo']) . "' "
      . "where paperId=$paperId";

  $result = $Conf->q($query);
  if ( DB::isError($result) ) {
    $Conf->errorMsg("Update failed: " . $result->getMessage());
    exit();
  }

  $Conf->infoMsg("Updated information for #$paperId");

  $Conf->log("Updated paper information for $paperId", $_SESSION["Me"]);

	
  if ( IsSet($_REQUEST["topics"]) ) {
    setTopics($paperId,
	      $_REQUEST["topics"]);
  }

  if ( IsSet($_REQUEST["preferredReviewers"]) ) {
    setPreferredReviewers($paperId,
			  $_REQUEST["preferredReviewers"]);
  }
}

  $query = "SELECT title, abstract, authorInformation, "
  . " acknowledged, withdrawn, collaborators "
  . " FROM Paper " 
  . " WHERE Paper.paperId=$paperId";

  $result = $Conf->q($query);

if ( DB::isError($result) ) {
  $Conf->errorMsg("Unable to read information about paper: "
		  . $result->getMessage());
  exit;
} 

$row = $result->fetchRow();
$_REQUEST["title"]=$row[0];
$_REQUEST["abstract"]=$row[1];
$_REQUEST["authorInfo"]=$row[2];
$finalized=$row[3];
$withdrawn=$row[4];
$_REQUEST["collaborators"]=$row[5];
?>

<p>
Modify any of the following information, and then hit "Update Fields"
at the top or bottom of the page.
</p>

<table align=center>
<tr>
<td>
<?php 
$Conf->textButton("Click here to go to the paper list",
		  "../index.php");
?>
</td>
</tr>
</table>

&nbsp;<br>
&nbsp;<br>

<form method="POST" action="<?php echo $_SERVER[PHP_SELF] ?>"
ENCTYPE="multipart/form-data" >
<?php echo $Conf->mkHiddenVar('paperId', $paperId)?>
<center> <input type="submit" value="Update Fields" name="submit"> </center>
<?php
    //
    // Get the topics for this paper - I couldn't figure
    // out how to do this as a left join
    //
    $topics = getTopics($paperId);

    $preferredReviewers = getPreferredReviewers($paperId);

    paperForm($Conf,$_REQUEST["title"],$_REQUEST["abstract"],
	      $_REQUEST["authorInfo"],$_REQUEST["collaborators"],
	      $topics, $preferredReviewers);

?>
<div align="center"> <center>
<p> <input type="submit" value="Update Fields" name="submit"> </p>
</center> </div>
</form>

<?php $Conf->footer() ?>
