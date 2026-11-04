<?php
require_once __DIR__.'/../Services/auth.php';
$_SESSION["page"] = "REGISTRATION";

$selected = isset($request["acount_category"])?
          $request["acount_category"].""
          : "STUDENT"  ;

?>