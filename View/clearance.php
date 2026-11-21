<?php
require_once __DIR__ . "/../PageLogic/clearance_logic.php";

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AMS | Clearance</title>
  <link rel="icon" type="image/x-icon" href="<?= BASE_PATH ?>/View/resources/favicon1.jpg">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/general.css">
  <link rel="stylesheet" href="<?= BASE_PATH ?>/View/style/clearance.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
  <?php require_once __DIR__ . "/components/popup_message.php"; ?>
  <div class="row main_box">
    <?php require_once __DIR__ . "/components/navigation.php"; ?>
    <div class="column" style="flex: 1;">
      <?php require_once __DIR__ . "/components/header.php"; ?>
      <main class="column scrollbar" style="gap:20px">
        
        <?php if ($_SESSION["role"] == "student"): ?>
          <?php if ($student_view == "MAIN"): ?>
            <div class="row" style=" gap:20px">
              <form method="get" action="<?= BASE_PATH ?>/clearance" class="clearance_categ_box drop_shadow" onclick="this.submit()">
                <img src="<?= BASE_PATH ?>/View/resources/image1.png" class="clearance_categ_box_upper_part">
                
                <div class="clearance_categ_box_buttom_part column">
                  <b>SUBJECTS</b>
                </div>
                <input type="hidden" name="view" value="SUBJECTS">
              </form>
              <form method="get" action="<?= BASE_PATH ?>/clearance" class="clearance_categ_box drop_shadow" onclick="this.submit()">
                <img src="<?= BASE_PATH ?>/View/resources/image2.png" class="clearance_categ_box_upper_part">

                <div class="clearance_categ_box_buttom_part column">
                  <b>FACULTY</b>
                </div>
                <input type="hidden" name="view" value="FACULTY">
              </form>
              <form method="get" action="<?= BASE_PATH ?>/clearance" class="clearance_categ_box drop_shadow" onclick="this.submit()">
                <img src="<?= BASE_PATH ?>/View/resources/image3.png" class="clearance_categ_box_upper_part">

                <div class="clearance_categ_box_buttom_part column">
                  <b>OFFICE</b>
                </div>
                <input type="hidden" name="view" value="OFFICE">
              </form>
            </div>

            <div class="row" style="gap:20px">
              <div class="column" style="gap:20px">
                <div class="row" style="justify-content:space-between; align-items:center; color:white; ">
                  <b style=" font-size:30px; padding-left:20px">UPDATES</b>
                  <a class="row" href="<?= BASE_PATH ?>/clearance?view=UPDATES" style="padding-right:20px ; align-items:center; text-decoration: none; gap:10px">
                    <b style=" font-size:25px">View All Updates</b>
                    <div><img src="<?= BASE_PATH ?>/View/resources/arrow.png" alt="" style="width:40px"></div>
                  </a>

                </div>
                <div class="updates_frame  drop_shadow">
                  <table class="updates_table">
                    <thead>
                      <tr>
                        <th class="col_name">CATEGORY</th>
                        <th class="col_about">ABOUT</th>
                        <th class="col_date">DATE</th>
                        <th class="col_status">STATUS</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($updates_record as $row): ?>
                        <tr>
                          <td title="<?= htmlspecialchars($row['category']) ?>">
                            <?= htmlspecialchars($row['category']) ?>
                          </td>
                          <td title="<?= htmlspecialchars($row['item_name']) ?>">
                            <?= htmlspecialchars($row['item_name']) ?>
                          </td>
                          <td><?= htmlspecialchars($row['date_cleared']) ?></td>
                          <td>
                            <span class="status_badge <?= statusClass($row['status']) ?>">
                              <?= htmlspecialchars(strtoupper($row['status'])) ?>
                            </span>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="pie_chart drop_shadow">
                <canvas id="pie"></canvas>
              </div>
            </div>

          <?php elseif ($student_view == "UPDATES"): ?>
            <div class="row" style="justify-content: space-between; align-items:center">
              <b class="view_title">CLEARANCE UPDATES</b>
              <form  class="row" style="gap:20px; align-items:center; " method="get" action="<?= BASE_PATH ?>/clearance">
                <b>ACAD-YEAR:</b>
                <select name="acadyearfilter" class="filter" onchange="this.form.submit()">
                  <?php $options = []; foreach( getClearanceUpdates() as $subject): if(!in_array($subject["acad_year_sem"],$options)): $options[] = $subject["acad_year_sem"];?>
                    <option  value="<?= $subject["acad_year_sem"] ?>" <?php if($acadyear == $subject["acad_year_sem"]) echo 'selected'?>><?= $subject["acad_year_sem"] ?></option>
                  <?php endif; endforeach ?>
                </select>
                <input type="hidden" name="view" value="UPDATES">
              </form>
              <a href="<?= BASE_PATH ?>/clearance" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
            </div>
            <div class="updates_frame2  ">
              <table class="updates_table">
                <thead style="position: relative;z-index: 1;">
                  <tr>
                    <th class="col_name">CATEGORY</th>
                    <th class="col_about">ABOUT</th>
                    <th class="col_date">DATE</th>
                    <th class="col_status">STATUS</th>
                  </tr>
                </thead>
                <tbody class="updates_records">
                  <?php foreach (getClearanceUpdates($acadyear) as $row): ?>
                    <tr onclick="toggleRemark(this)" class="update_record">
                      <td title="<?= htmlspecialchars($row['category']) ?>">
                        <?= htmlspecialchars($row['category']) ?>
                      </td>
                      <td title="<?= htmlspecialchars($row['item_name']) ?>">
                        <?= htmlspecialchars($row['item_name']) ?>
                      </td>
                      <td><?= htmlspecialchars($row['date_cleared']) ?></td>
                      <td>
                        <span class="status_badge <?= statusClass($row['status']) ?>">
                          <?= htmlspecialchars(strtoupper($row['status'])) ?>
                        </span>
                        <div class="remark" >
                          <b>REMARKS</b>
                          <p><?=  $row['remarks'] ?> </p>
                        </div>
                      </td>
                      
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>              
          <?php elseif ($student_view === "SUBJECTS"): $subjects = getSubjectsClearance($acadyear,$search,$status)?>
                    <?php var_dump($acadyear.$search.$status);var_dump(getSubjectsClearance($acadyear)) ?>
            <div class="row" style="justify-content: space-between; align-items:center">
              <b class="view_title">SUBJECTS</b>
              <a href="<?= BASE_PATH ?>/clearance" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
            </div>
            <form class="row" style="gap: 20px;" action="<?= BASE_PATH ?>/clearance" method="get">
              <input type="hidden" name="view" value="SUBJECTS">
              <div class="row searchbar_frame">
                <input type="text" name="search"  placeholder="SEARCH" class="searchbar" value="<?= htmlspecialchars($search === "ALL" ? '':$search) ?>">
                <button type="submit" onclick="this.form.submit()" style="border: 0px;background-color:transparent;">
                    <img src="<?= BASE_PATH?>/View/resources/search.png" alt="" class="back_buttons search_icon" style="padding:5px">
                </button>
              </div>
              
              <select name="statusfilter" class="filter" onchange="this.form.submit()">
                <option value="ALL" <?= $status === 'ALL' ? 'selected' : '' ?>>ALL</option>
                <?php $options = []; foreach( getSubjectsClearance($acadyear) as $subject): if(!in_array($subject["status"],$options)): $options[] = $subject["status"];?>
                  <option  value="<?= $subject["status"] ?>" <?php if($status == $subject["status"]) echo 'selected'?>><?= $subject["status"] ?></option>
                <?php endif; endforeach ?>
              </select>

              <div  class="row" style="gap:20px; align-items:center; ">
                <b>ACAD-YEAR:</b>
                <select name="acadyearfilter" class="filter" onchange="this.form.submit()">
                  <?php $options = []; foreach( getSubjectsClearance() as $subject): if(!in_array($subject["acad_year_sem"],$options)): $options[] = $subject["acad_year_sem"];?>
                    <option  value="<?= $subject["acad_year_sem"] ?>" <?php if($acadyear == $subject["acad_year_sem"]) echo 'selected'?>><?= $subject["acad_year_sem"] ?></option>
                  <?php endif; endforeach ?>
                </select>
              </div>
            </form>
            <div class="column" style="gap: 20px;">
              <?php foreach( $subjects as $subject):?>
                
                <form class="row clearance_box  drop_shadow" action="<?= BASE_PATH ?>/clearance" method="get">
                  <div class="column">
                    <b style="font-size: 30px;"><?= $subject["subjectID"].' - '.$subject["name"] ?></b>
                    <b style="font-size: 20px;"><?= $subject["prof_name"] ?></b>
                    <b style="font-size: 20px;"><?= $subject["room"] ?></b>
                  </div>
                  <div >
                    <input type="hidden" name="view" value="SUBJECT-REQUIREMENTS">
                    <button type="submit" onclick="this.form.submit()" style="background-color: transparent; border:0px;">
                      <img src="<?= BASE_PATH?>/View/resources/openClearance.png" alt="" class="open_button" >
                    </button>
                    <input type="hidden" name="search" value="<?php echo $subject["subjectID"] ?>">
                    <input type="hidden" name="acadyear" value="<?php echo $acadyear ?>">
                  </div>

                </form>
              <?php endforeach ?>
            </div> 

          <?php elseif ($student_view === "SUBJECT-REQUIREMENTS"):$acadyear = $_GET['acadyear']; $subject_info = getSubjectRequireMents($search,$acadyear); ?>
                <?php echo ( $search.$acadyear) ?>
          
                <div class="row" style="justify-content: space-between; align-items:center">
                  <b class="view_title"><?= $subject_info[0]["name"] ?></b>
                  <a href="<?= BASE_PATH ?>/clearance?view=SUBJECTS" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
                </div>
                <div class="row subject_container">
                  <div class="column">
                    <b>NAME: <?= $subject_info[0]["prof_name"] ?></b>
                    <b>SUBJECT CODE: <?= $subject_info[0]["subjectID"]?></b>
                    
                    
                  </div>
                  <div class="column">
                    <b>CLASS TIME: <?= $subject_info[0]["class_time"] ?></b>
                    <b>ROOM: <?= $subject_info[0]["room"] ?></b>
                    
                  </div>
                </div>

                <div class="requirement_container">
                  <div class="clearance_instructions" style="font-size:20px">
                    <b style="font-size:25px">How to submit your clearance requirement</b>
                    <p>Upload the document that proves you have completed this requirement for this clearance.</p>
                    <ol>
                      <li>Accepted formats: PDF, DOC, or DOCX (maximum 40MB).</li>
                      <li>Make sure the file is complete, readable, and shows your name and student number.</li>
                      <li>Choose your file, then click <b>Upload document</b>.</li>
                      <li>Wait for the confirmation message. Your requirement stays <i>Not yet cleared</i> until it is reviewed.</li>
                      <li>If it is rejected, read the remarks, fix the issue, and upload a new file.</li>
                      <li>If your file is larger than 40 MB, upload it to your own Google Drive and set sharing to
                          <b>"Anyone with the link can view"</b>.<br> Copy the link and paste it into a new document,
                          along with your name and student number. <br>Export that document as a PDF and upload the PDF here.
                          Do not edit, move, or delete the file in your Drive until your requirement is cleared.</li>
                    </ol>
                    <p>For questions, contact the person or office in charge of this requirement.</p>
                  </div>
                  <div class="row" style="gap:20px; flex-wrap:wrap; justify-content:center" >
                    <?php foreach($subject_info as $requirement): if ($requirement["file_name"] === null) continue;?>
                      <div class="row file_container"  >
                        <div class="row" style="align-items:center;">
                          <img src="<?= BASE_PATH?>/View/resources/Document.png" alt="" class="file_icons">
                          <b><?= $requirement["file_name"]?></b>
                        </div>
                        
                        <b><?= $requirement['file_size'] ?></b>
                        <!-- <div class="row" style="align-items:center;">
                          <a href=""><img src="<= BASE_PATH?>/View/resources/Eye.png" alt="" class="file_icons"></a>
                          <a href=""><img src="<= BASE_PATH?>/View/resources/Download.png" alt="" class="file_icons"></a>
                        </div> -->
                        <div class="row" style="align-items:center;">
                          <?php if ($requirement['mime_type'] === 'application/pdf'): ?>
                            <a href="<?= BASE_PATH ?>/view-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearance&amp;categ=S" target="_blank">
                              <img src="<?= BASE_PATH ?>/View/resources/Eye.png" alt="View" class="file_icons">
                            </a>
                          <?php endif ?>
                          
                          <a href="<?= BASE_PATH ?>/download-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearance&amp;categ=S&amp;download=1">
                            <img src="<?= BASE_PATH ?>/View/resources/Download.png" alt="Download" class="file_icons">
                          </a>
                        </div>
                      </div>

                    <?php endforeach ?>
                      
                  </div>
                  
                </div>
                <div class="row fileupload_buttom">
                  <a href="<?= BASE_PATH ?>/clearance?view=SUBJECT-REQUIREMENTS&search=<?= $search ?>&upload=1&acadyear=<?= $_GET['acadyear']?>">
                    <button type="button">UPLOAD FILE</button>
                  </a>
                  <a href="<?= BASE_PATH ?>/clearance?view=SUBJECT-REQUIREMENTS&search=<?= $search ?>&uploaded=1&acadyear=<?= $_GET['acadyear']?>">
                    <button type="button">VIEW UPLOADS</button>
                  </a>


                  <?php if(isset($_GET['upload']) && ($_GET['upload'] == 1)): ?>
                    <form class="uploads_container upload_container" method="POST" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-document">
                      <div class="row upload_header">
                        <b>YOUR FILES</b>
                        <a href="<?= BASE_PATH ?>/clearance?view=SUBJECT-REQUIREMENTS&search=<?= $search ?>&acadyear=<?= $_GET['acadyear']?>">
                          <img src="<?= BASE_PATH ?>/View/resources/Close.png" alt="Close" class="back_buttons">
                        </a>
                      </div>
                      

                      <div class="upload_body">
                        <hr>
                        <div class="files_section">
                          <h3>Files Uploaded</h3>
                          <p class="no_files">No files attached yet.</p>
                          <ul class="file_list"></ul>
                        </div>
                        <hr>
                      </div>

                      <div class="row upload_actions">
                        <label for="document" class="pill_btn">ATTACH FILE</label>
                        <button type="submit" class="pill_btn">SUBMIT</button>

                        <input type="file" id="document" name="document"  hidden
                              accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                      </div>
                      <input type="hidden" name="docs_for" value="addSubjectRequirement">
                      <input type="hidden" name="ID" value="<?= $subject_info[0]['subjectID'] ?>">
                      <input type="hidden" name="enrollmentID" value="<?= $subject_info[0]['enrollmentID'] ?>">
                      <input type="hidden" name="page_uploader" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    </form>

                  <?php  elseif(isset($_GET['uploaded']) && ($_GET['uploaded']  == 1)): $uploaded_files = getUploadedSubjectRequireMents($acadyear,$search);?>
                    <div class="uploads_container uploaded_container">
                        <div class="row"  style="justify-content: space-between;">
                            <b>UPLOADED FILES</b>
                            <a href="<?= BASE_PATH ?>/clearance?view=SUBJECT-REQUIREMENTS&search=<?= $search ?>&acadyear=<?= $_GET['acadyear']?>">
                              <img src="<?= BASE_PATH?>/View/resources/Close.png" alt="" class="back_buttons"></a>
                        </div>
                        <hr>
                        <div class="column" style="gap:20px;  " >
                          <?php foreach($uploaded_files as $requirement): if ($requirement["file_name"] === null) continue;?>
                            <div class="row uploaded_file_container"  >
                              <div class="row" style="align-items:center;">
                                <img src="<?= BASE_PATH?>/View/resources/Document.png" alt="" class="file_icons">
                                <b><?= $requirement["file_name"]?></b>
                              </div>
                              
                              <b><?= $requirement['file_size'] ?></b>
                              <!-- <div class="row" style="align-items:center;">
                                <a href=""><img src="<= BASE_PATH?>/View/resources/Eye.png" alt="" class="file_icons"></a>
                                <a href=""><img src="<= BASE_PATH?>/View/resources/Download.png" alt="" class="file_icons"></a>
                              </div> -->
                              <div class="row" style="align-items:center;">
                                <?php if ($requirement['mime_type'] === 'application/pdf'): ?>
                                  <a href="<?= BASE_PATH ?>/view-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearance&amp;categ=S" target="_blank">
                                    <img src="<?= BASE_PATH ?>/View/resources/Eye.png" alt="View" class="file_icons">
                                  </a>
                                <?php endif ?>
                                
                                <form action="<?= BASE_PATH ?>/delete-document" method="post" onsubmit="return confirm('Delete this file? This cannot be undone.')">
                                  <input type="hidden" name="categ" value="S">
                                  <input type="hidden" name="ID" value="<?= (int) $requirement['ID'] ?>">
                                  <input type="hidden" name="page_uploader" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

                                  <button type="submit" style="background-color: transparent;border:0">
                                    <img src="<?= BASE_PATH ?>/View/resources/Trash.png" alt="Download" class="file_icons"></button>
                                </form>
                              </div>
                            </div>

                          <?php endforeach ?>
                        </div>
                    </div>
                  <?php endif ?>
                </div>

                

          <?php elseif ($student_view === "FACULTY"): $faculties = getFacultyClearance($acadyear,$search,$status); ?>
            <div class="row" style="justify-content: space-between; align-items:center">
              <b class="view_title">FACULTY</b>
              <a href="<?= BASE_PATH ?>/clearance" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
            </div>
            <form class="row" style="gap: 20px;" action="<?= BASE_PATH ?>/clearance" method="get">
              <input type="hidden" name="view" value="FACULTY">
              <div class="row searchbar_frame">
                <input type="text" name="search"  placeholder="SEARCH" class="searchbar" value="<?= htmlspecialchars($_GET["search"] ??  '') ?>">
                <button type="submit" onclick="this.form.submit()" style="border: 0px;background-color:transparent;">
                    <img src="<?= BASE_PATH?>/View/resources/search.png" alt="" class="back_buttons search_icon" style="padding:5px">
                </button>
              </div>
              
              <select name="statusfilter" class="filter" onchange="this.form.submit()">
                <option value="ALL" <?= $status === 'ALL' ? 'selected' : '' ?>>ALL</option>
                <?php $options = []; foreach( getFacultyClearance() as $faculty): if(!in_array($faculty["status"],$options)): $options[] = $faculty["status"];?>
                  <option  value="<?= $faculty["status"] ?>" <?php if($status == $faculty["status"]) echo 'selected'?>><?= $faculty["status"] ?></option>
                <?php endif; endforeach ?>
              </select>

              <div  class="row" style="gap:20px; align-items:center; ">
                <b>ACAD-YEAR:</b>
                <select name="acadyearfilter" class="filter" onchange="this.form.submit()">
                  <?php $options = []; foreach( getFacultyClearance() as $subject): if(!in_array($subject["acad_year_sem"],$options)): $options[] = $subject["acad_year_sem"];?>
                    <option  value="<?= $subject["acad_year_sem"] ?>" <?php if($acadyear == $subject["acad_year_sem"]) echo 'selected'?>><?= $subject["acad_year_sem"] ?></option>
                  <?php endif; endforeach ?>
                </select>
              </div>
            </form>
            <div class="column" style="gap: 20px;">
              <?php foreach( $faculties as $faculty):?>
                
                <form class="row clearance_box" action="<?= BASE_PATH ?>/clearance" method="get">
                  <div class="column">
                    <b style="font-size: 30px;"><?= strtoupper($faculty["role"]) ?></b>
                    <b style="font-size: 20px;"><?= $faculty["prof_name"] ?></b>
                  </div>
                  <div >
                    <button type="submit" onclick="this.form.submit()" style="background-color: transparent; border:0px;">
                      <img src="<?= BASE_PATH?>/View/resources/openClearance.png" alt="" class="open_button" >
                    </button>
                    <input type="hidden" name="view" value="FACULTY-REQUIREMENTS">
                    <input type="hidden" name="search" value="<?php echo $faculty["facultyID"] ?>">
                    <input type="hidden" name="acadyear" value="<?php echo $acadyear ?>">
                  </div>
                </form>
              <?php endforeach ?>
            </div> 

          <?php elseif ($student_view === "FACULTY-REQUIREMENTS"): $acadyear = $_GET['acadyear'];  $faculty_info = getFacultyRequireMents($acadyear,$search); ?>
            <div class="row" style="justify-content: space-between; align-items:center">
              <b class="view_title">FACULTY</b>
              <a href="<?= BASE_PATH ?>/clearance?view=FACULTY" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
            </div>
            <div class="row subject_container">
              <div class="column">
                <b>NAME: <?= $faculty_info[0]["prof_name"] ?></b>
                <b>ROLE: <?= $faculty_info[0]["role"]?></b>
                
                
              </div>
            </div>

            <div class="requirement_container">
              <div class="clearance_instructions" style="font-size:20px">
                <b style="font-size:25px">How to submit your clearance requirement</b>
                <p>Upload the document that proves you have completed this requirement for this clearance.</p>
                <ol>
                  <li>Accepted formats: PDF, DOC, or DOCX (maximum 40MB).</li>
                  <li>Make sure the file is complete, readable, and shows your name and student number.</li>
                  <li>Choose your file, then click <b>Upload document</b>.</li>
                  <li>Wait for the confirmation message. Your requirement stays <i>Not yet cleared</i> until it is reviewed.</li>
                  <li>If it is rejected, read the remarks, fix the issue, and upload a new file.</li>
                  <li>If your file is larger than 40 MB, upload it to your own Google Drive and set sharing to
                      <b>"Anyone with the link can view"</b>.<br> Copy the link and paste it into a new document,
                      along with your name and student number. <br>Export that document as a PDF and upload the PDF here.
                      Do not edit, move, or delete the file in your Drive until your requirement is cleared.</li>
                </ol>
                <p>For questions, contact the person or office in charge of this requirement.</p>
              </div>
              <div class="row" style="gap:20px; flex-wrap:wrap; justify-content:center" >
                <?php foreach($faculty_info as $requirement): if ($requirement["file_name"] === null) continue;?>
                  <div class="row file_container"  >
                    <div class="row" style="align-items:center;">
                      <img src="<?= BASE_PATH?>/View/resources/Document.png" alt="" class="file_icons">
                      <b><?= $requirement["file_name"]?></b>
                    </div>
                    
                    <b><?= $requirement['file_size'] ?></b>
                    <!-- <div class="row" style="align-items:center;">
                      <a href=""><img src="<= BASE_PATH?>/View/resources/Eye.png" alt="" class="file_icons"></a>
                      <a href=""><img src="<= BASE_PATH?>/View/resources/Download.png" alt="" class="file_icons"></a>
                    </div> -->
                    <div class="row" style="align-items:center;">
                      <?php if ($requirement['mime_type'] === 'application/pdf'): ?>
                        <a href="<?= BASE_PATH ?>/view-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearance&amp;categ=F" target="_blank">
                          <img src="<?= BASE_PATH ?>/View/resources/Eye.png" alt="View" class="file_icons">
                        </a>
                      <?php endif ?>
                      
                      <a href="<?= BASE_PATH ?>/download-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearance&amp;categ=F&amp;download=1">
                        <img src="<?= BASE_PATH ?>/View/resources/Download.png" alt="Download" class="file_icons">
                      </a>
                    </div>
                  </div>

                <?php endforeach ?>
                  
              </div>
              
            </div>
            <div class="row fileupload_buttom">
                  <a href="<?= BASE_PATH ?>/clearance?view=FACULTY-REQUIREMENTS&search=<?= $search ?>&upload=1&acadyear=<?= $_GET['acadyear'] ?>">
                    <button type="button">UPLOAD FILE</button>
                  </a>
                  <a href="<?= BASE_PATH ?>/clearance?view=FACULTY-REQUIREMENTS&search=<?= $search ?>&uploaded=1&acadyear=<?= $_GET['acadyear'] ?>">
                    <button type="button">VIEW UPLOADS</button>
                  </a>


                  <?php if(isset($_GET['upload']) && ($_GET['upload'] == 1)): ?>
                    <form class="uploads_container upload_container" method="POST" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-document">
                      <div class="row upload_header">
                        <b>YOUR FILES</b>
                        <a href="<?= BASE_PATH ?>/clearance?view=FACULTY-REQUIREMENTS&search=<?= $search ?>&acadyear=<?= $_GET['acadyear'] ?>">
                          <img src="<?= BASE_PATH ?>/View/resources/Close.png" alt="Close" class="back_buttons">
                        </a>
                      </div>
                      

                      <div class="upload_body">
                        <hr>
                        <div class="files_section">
                          <h3>Files Uploaded</h3>
                          <p class="no_files">No files attached yet.</p>
                          <ul class="file_list"></ul>
                        </div>
                        <hr>
                      </div>

                      <div class="row upload_actions">
                        <label for="document" class="pill_btn">ATTACH FILE</label>
                        <button type="submit" class="pill_btn">SUBMIT</button>

                        <input type="file" id="document" name="document"  hidden
                              accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                      </div>
                      <input type="hidden" name="docs_for" value="addFacultyRequirement">
                      <input type="hidden" name="ID" value="<?= $faculty_info[0]['faculty_clearanceID'] ?>">
                      <input type="hidden" name="page_uploader" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    </form>

                  <?php  elseif(isset($_GET['uploaded']) && ($_GET['uploaded']  == 1)): $uploaded_files = getUploadedFacultyRequireMents($acadyear,$search);?>
                    <div class="uploads_container uploaded_container">
                        <div class="row"  style="justify-content: space-between;">
                            <b>UPLOADED FILES</b>
                            <a href="<?= BASE_PATH ?>/clearance?view=FACULTY-REQUIREMENTS&search=<?= $search ?>&acadyear=<?= $_GET['acadyear'] ?>">
                              <img src="<?= BASE_PATH?>/View/resources/Close.png" alt="" class="back_buttons"></a>
                        </div>
                        <hr>
                        <div class="column" style="gap:20px;  " >
                          <?php foreach($uploaded_files as $requirement): if ($requirement["file_name"] === null) continue;?>
                            <div class="row uploaded_file_container"  >
                              <div class="row" style="align-items:center;">
                                <img src="<?= BASE_PATH?>/View/resources/Document.png" alt="" class="file_icons">
                                <b><?= $requirement["file_name"]?></b>
                              </div>
                              
                              <b><?= $requirement['file_size'] ?></b>
                              <!-- <div class="row" style="align-items:center;">
                                <a href=""><img src="<= BASE_PATH?>/View/resources/Eye.png" alt="" class="file_icons"></a>
                                <a href=""><img src="<= BASE_PATH?>/View/resources/Download.png" alt="" class="file_icons"></a>
                              </div> -->
                              <div class="row" style="align-items:center;">
                                <?php if ($requirement['mime_type'] === 'application/pdf'): ?>
                                  <a href="<?= BASE_PATH ?>/view-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearance&amp;categ=F" target="_blank">
                                    <img src="<?= BASE_PATH ?>/View/resources/Eye.png" alt="View" class="file_icons">
                                  </a>
                                <?php endif ?>
                                
                                <form action="<?= BASE_PATH ?>/delete-document" method="post" onsubmit="return confirm('Delete this file? This cannot be undone.')">
                                  <input type="hidden" name="categ" value="F">
                                  <input type="hidden" name="ID" value="<?= (int) $requirement['ID'] ?>">
                                  <input type="hidden" name="page_uploader" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

                                  <button type="submit" style="background-color: transparent;border:0">
                                    <img src="<?= BASE_PATH ?>/View/resources/Trash.png" alt="Download" class="file_icons"></button>
                                </form>
                              </div>
                            </div>

                          <?php endforeach ?>
                        </div>
                    </div>
                  <?php endif ?>
                </div>
            
          <?php elseif ($student_view === "OFFICE"): $offices = getOfficeClearance($acadyear,$search,$status);?>
            <div class="row" style="justify-content: space-between; align-items:center">
              <b class="view_title">OFFICE</b>
              <a href="<?= BASE_PATH ?>/clearance" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
            </div>
            <form class="row" style="gap: 20px;" action="<?= BASE_PATH ?>/clearance" method="get">
              <input type="hidden" name="view" value="OFFICE">
              <div class="row searchbar_frame">
                <input type="text" name="search"  placeholder="SEARCH" class="searchbar" value="<?= htmlspecialchars($_GET["search"] ??  '') ?>">
                <button type="submit" onclick="this.form.submit()" style="border: 0px;background-color:transparent;">
                    <img src="<?= BASE_PATH?>/View/resources/search.png" alt="" class="back_buttons search_icon" style="padding:5px">
                </button>
              </div>
              
              <select name="statusfilter" class="filter" onchange="this.form.submit()">
                <option value="ALL" <?= $status === 'ALL' ? 'selected' : '' ?>>ALL</option>
                <?php $options = []; foreach( getOfficeClearance() as $faculty): if(!in_array($faculty["status"],$options)): $options[] = $faculty["status"];?>
                  <option  value="<?= $faculty["status"] ?>" <?php if($status == $faculty["status"]) echo 'selected'?>><?= $faculty["status"] ?></option>
                <?php endif; endforeach ?>
              </select>

              <div  class="row" style="gap:20px; align-items:center; ">
                <b>ACAD-YEAR:</b>
                <select name="acadyearfilter" class="filter" onchange="this.form.submit()">
                  <?php $options = []; foreach( getOfficeClearance() as $subject): if(!in_array($subject["acad_year_sem"],$options)): $options[] = $subject["acad_year_sem"];?>
                    <option  value="<?= $subject["acad_year_sem"] ?>" <?php if($acadyear == $subject["acad_year_sem"]) echo 'selected'?>><?= $subject["acad_year_sem"] ?></option>
                  <?php endif; endforeach ?>
                </select>
              </div>
            </form>
            <div class="column" style="gap: 20px;">
              <?php foreach( $offices as $office):?>
                
                <form class="row clearance_box" action="<?= BASE_PATH ?>/clearance" method="get">
                  <div class="column">
                    <b style="font-size: 30px;"><?= strtoupper($office["name"]) ?></b>
                    <b style="font-size: 20px;"><?= strtoupper($office["prof_name"]) ?></b>
                  </div>
                  <div >
                    <button type="submit" onclick="this.form.submit()" style="background-color: transparent; border:0px;">
                      <img src="<?= BASE_PATH?>/View/resources/openClearance.png" alt="" class="open_button" >
                    </button>
                    <input type="hidden" name="view" value="OFFICE-REQUIREMENTS">
                    <input type="hidden" name="search" value="<?php echo $office["officeID"] ?>">
                    <input type="hidden" name="acadyear" value="<?php echo $acadyear ?>">
                  </div>
                </form>
              <?php endforeach ?>
            </div> 
          
          
          <?php elseif ($student_view === "OFFICE-REQUIREMENTS"): $acadyear = $_GET['acadyear']; $office_info = getOfficeRequireMents($acadyear,$search); ?>
            <div class="row" style="justify-content: space-between; align-items:center">
              <b class="view_title"><?= strtoupper($office_info[0]["role"])." " ?>OFFICE</b>
               <a href="<?= BASE_PATH ?>/clearance?view=OFFICE" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
            </div>
            <!-- <div class="row subject_container">
              <div class="column">
                <b>NAME: <?= strtoupper($office_info[0]["role"])." " ?></b>
                <b>ROLE: <?= $office_info[0]["name"]?></b>
                
                
              </div>
            </div> -->

            <div class="requirement_container">
              <div class="clearance_instructions" style="font-size:20px">
                <b style="font-size:25px">How to submit your clearance requirement</b>
                <p>Upload the document that proves you have completed this requirement for this clearance.</p>
                <ol>
                  <li>Accepted formats: PDF, DOC, or DOCX (maximum 40MB).</li>
                  <li>Make sure the file is complete, readable, and shows your name and student number.</li>
                  <li>Choose your file, then click <b>Upload document</b>.</li>
                  <li>Wait for the confirmation message. Your requirement stays <i>Not yet cleared</i> until it is reviewed.</li>
                  <li>If it is rejected, read the remarks, fix the issue, and upload a new file.</li>
                  <li>If your file is larger than 40 MB, upload it to your own Google Drive and set sharing to
                      <b>"Anyone with the link can view"</b>.<br> Copy the link and paste it into a new document,
                      along with your name and student number. <br>Export that document as a PDF and upload the PDF here.
                      Do not edit, move, or delete the file in your Drive until your requirement is cleared.</li>
                </ol>
                <p>For questions, contact the person or office in charge of this requirement.</p>
              </div>
              <div class="row" style="gap:20px; flex-wrap:wrap; justify-content:center" >
                <?php foreach($office_info as $requirement): if ($requirement["file_name"] === null) continue;?>
                  <div class="row file_container"  >
                    <div class="row" style="align-items:center; overflow:hidden">
                      <img src="<?= BASE_PATH?>/View/resources/Document.png" alt="" class="file_icons">
                      <b class="filename"><?= $requirement["file_name"]?></b>
                    </div>
                    
                    <b class="file_size"><?= $requirement['file_size'] ?></b>
                    <!-- <div class="row" style="align-items:center;">
                      <a href=""><img src="<= BASE_PATH?>/View/resources/Eye.png" alt="" class="file_icons"></a>
                      <a href=""><img src="<= BASE_PATH?>/View/resources/Download.png" alt="" class="file_icons"></a>
                    </div> -->
                    <div class="row" style="align-items:center;">
                      <?php if ($requirement['mime_type'] === 'application/pdf'): ?>
                        <a href="<?= BASE_PATH ?>/view-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearance&amp;categ=O" target="_blank">
                          <img src="<?= BASE_PATH ?>/View/resources/Eye.png" alt="View" class="file_icons">
                        </a>
                      <?php endif ?>
                      
                      <a href="<?= BASE_PATH ?>/download-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearance&amp;categ=O&amp;download=1">
                        <img src="<?= BASE_PATH ?>/View/resources/Download.png" alt="Download" class="file_icons">
                      </a>
                    </div>
                  </div>

                <?php endforeach ?>
                  
              </div>
              
            </div>
            <div class="row fileupload_buttom">
                  <a href="<?= BASE_PATH ?>/clearance?view=OFFICE-REQUIREMENTS&search=<?= $search ?>&upload=1&acadyear=<?= $_GET['acadyear'] ?>">
                    <button type="button">UPLOAD FILE</button>
                  </a>
                  <a href="<?= BASE_PATH ?>/clearance?view=OFFICE-REQUIREMENTS&search=<?= $search ?>&uploaded=1&acadyear=<?= $_GET['acadyear'] ?>">
                    <button type="button">VIEW UPLOADS</button>
                  </a>


                  <?php if(isset($_GET['upload']) && ($_GET['upload'] == 1)): ?>
                    <form class="uploads_container upload_container" method="POST" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-document">
                      <div class="row upload_header">
                        <b>YOUR FILES</b>
                        <a href="<?= BASE_PATH ?>/clearance?view=OFFICE-REQUIREMENTS&search=<?= $search ?>&acadyear=<?= $_GET['acadyear'] ?>">
                          <img src="<?= BASE_PATH ?>/View/resources/Close.png" alt="Close" class="back_buttons">
                        </a>
                      </div>
                      

                      <div class="upload_body">
                        <hr>
                        <div class="files_section">
                          <h3>Files Uploaded</h3>
                          <p class="no_files">No files attached yet.</p>
                          <ul class="file_list"></ul>
                        </div>
                        <hr>
                      </div>

                      <div class="row upload_actions">
                        <label for="document" class="pill_btn">ATTACH FILE</label>
                        <button type="submit" class="pill_btn">SUBMIT</button>

                        <input type="file" id="document" name="document"  hidden
                              accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                      </div>
                      <input type="hidden" name="docs_for" value="addOfficeRequirement">
                      <input type="hidden" name="ID" value="<?= $office_info[0]['office_clearanceID'] ?>">
                      <input type="hidden" name="page_uploader" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    </form>

                  <?php  elseif(isset($_GET['uploaded']) && ($_GET['uploaded']  == 1)): $uploaded_files = getUploadedOfficeRequireMents($acadyear,$search);?>
                    <div class="uploads_container uploaded_container">
                        <div class="row"  style="justify-content: space-between;">
                            <b>UPLOADED FILES</b>
                            <a href="<?= BASE_PATH ?>/clearance?view=OFFICE-REQUIREMENTS&search=<?= $search ?>&acadyear=<?= $_GET['acadyear'] ?>">
                              <img src="<?= BASE_PATH?>/View/resources/Close.png" alt="" class="back_buttons"></a>
                        </div>
                        <hr>
                        <div class="column" style="gap:20px;  " >
                          <?php foreach($uploaded_files as $requirement): if ($requirement["file_name"] === null) continue;?>
                            <div  class="row uploaded_file_container"  >
                              <div class="row" style="align-items:center;">
                                <img src="<?= BASE_PATH?>/View/resources/Document.png" alt="" class="file_icons">
                                <b><?= $requirement["file_name"]?></b>
                              </div>
                              
                              <b><?= $requirement['file_size'] ?></b>
                              <!-- <div class="row" style="align-items:center;">
                                <a href=""><img src="<= BASE_PATH?>/View/resources/Eye.png" alt="" class="file_icons"></a>
                                <a href=""><img src="<= BASE_PATH?>/View/resources/Download.png" alt="" class="file_icons"></a>
                              </div> -->
                              <div class="row" style="align-items:center;">
                                <?php if ($requirement['mime_type'] === 'application/pdf'): ?>
                                  <a href="<?= BASE_PATH ?>/view-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearance&amp;categ=O" target="_blank">
                                    <img src="<?= BASE_PATH ?>/View/resources/Eye.png" alt="View" class="file_icons">
                                  </a>
                                <?php endif ?>
                                
                                <form action="<?= BASE_PATH ?>/delete-document" method="post" onsubmit="return confirm('Delete this file? This cannot be undone.')">
                                  <input type="hidden" name="categ" value="O">
                                  <input type="hidden" name="ID" value="<?= (int) $requirement['ID'] ?>">
                                  <input type="hidden" name="page_uploader" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

                                  <button type="submit" style="background-color: transparent;border:0">
                                    <img src="<?= BASE_PATH ?>/View/resources/Trash.png" alt="Download" class="file_icons"></button>
                                </form>
                                
                              </div>
                            </div>

                          <?php endforeach ?>
                        </div>
                    </div>
                  <?php endif ?>
                </div>
          <?php endif ?>
          
          

        <?php elseif (in_array($_SESSION["role"], ["prof","dean","program coordinator","registrar"])):?>
          <?php if($student_view == "MAIN"): ?>

            

            <?php $offices = getOffices(); if(count($offices) > 0): ?>
              <div>
                <hr>
              </div>
              <b class="view_title">OFFICE</b>
              <div>
                <hr>
              </div>
              <div class="row" style=" gap:20px; flex-wrap: wrap; width:100%">
                <?php foreach($offices as $office):?>
                  <form method="get" action="<?= BASE_PATH ?>/clearance" class="clearance_categ_box drop_shadow" onclick="this.submit()">
                    <img src="<?= BASE_PATH ?>/View/resources/image1.png" class="clearance_categ_box_upper_part">
                    
                    <div class="clearance_categ_box_buttom_part column">
                      <b><?php echo $office['name'] ?></b>
                    </div>
                    <input type="hidden" name="view" value="OFFICE">
                  </form>
                <?php endforeach?>
              </div>

            <?php endif ?>

            <?php if(in_array($_SESSION["role"],["dean","program coordinator"])): ?>
              <div>
                <hr>
              </div>
              <b class="view_title">FACULTY</b>
              <div>
                <hr>
              </div>
              <div class="row" style=" gap:20px; flex-wrap: wrap; width:100%">
                <form method="get" action="<?= BASE_PATH ?>/clearance" class="clearance_categ_box drop_shadow" onclick="this.submit()">
                  <img src="<?= BASE_PATH ?>/View/resources/image3.png" class="clearance_categ_box_upper_part">
                  
                  <div class="clearance_categ_box_buttom_part column">
                    <b><?php echo $_SESSION["role"] ?></b>
                  </div>
                  <input type="hidden" name="view" value="FACULTY">
                </form>
              </div>
              
            <?php endif ?>


            <?php $subjects_handling = getSubjects(); 
                  if(count($subjects_handling) > 0): $filtered_subjects_handling = getSubjects($department,$search,$block,$year,$acadyear); ?>

                  <?php //var_dump($subjects_handling) ?>
              <div>
                <hr>
              </div>
              <?php //var_dump($department.$search.$block.$year.$acadyear) ?>
              <form class="column" style="gap: 20px;" action="<?= BASE_PATH ?>/clearance" method="get">
                <input type="hidden" name="view" value="MAIN">
                <div class="row" style="justify-content:space-between; align-items:center; gap:20px" >
                  <b class="view_title">SUBJECTS</b>
                  <div class="row searchbar_frame " style="width: 50%;">
                    <input type="text" name="search"  placeholder="SEARCH" class="searchbar" value="<?= htmlspecialchars($search === "ALL" ? '':$search) ?>">
                    <button type="submit" onclick="this.form.submit()" style="border: 0px;background-color:transparent;">
                        <img src="<?= BASE_PATH?>/View/resources/search.png" alt="" class="back_buttons search_icon" style="padding:5px">
                    </button>
                  </div>
                  <div  class="row" style="gap:20px; align-items:center; ">
                    <b>ACAD-YEAR:</b>
                    <select name="acadyearfilter" class="filter" onchange="this.form.submit()">
                      <?php $options = []; foreach( $subjects_handling as $subject): if(!in_array($subject["acad_year_sem"],$options)): $options[] = $subject["acad_year_sem"];?>
                        <option  value="<?= $subject["acad_year_sem"] ?>" <?php if($acadyear == $subject["acad_year_sem"]) echo 'selected'?>><?= $subject["acad_year_sem"] ?></option>
                      <?php endif; endforeach ?>
                    </select>
                  </div>
                  
                </div>
                

                <div class="row" style="gap: 20px; align-items:center; border-bottom: 2px solid white; padding-bottom: 20px;" >
                  <b>DEPT:</b>
                  <select name="filter" class="filter" onchange="this.form.submit()">
                    
                    <?php $options = []; foreach( $subjects_handling as $subject): if(!in_array($subject["department"],$options)): $options[] = $subject["department"];?>
                      <option  value="<?= $subject["department"] ?>" <?php if($department == $subject["department"]) echo 'selected'?>><?= $subject["department"] ?></option>
                    <?php endif; endforeach ?>
                  </select>
                  
                  <b>BLOCK:</b>
                  <select name="blockfilter" class="filter" onchange="this.form.submit()">
                    <option  value="ALL" <?php if($block == $subject["block"]) echo 'selected'?>>ALL</option>
                    <?php $options = []; foreach( $subjects_handling as $subject): if(!in_array($subject["block"],$options)): $options[] = $subject["block"];?>
                      <option  value="<?= $subject["block"] ?>" <?php if($block == $subject["block"]) echo 'selected'?>><?= $subject["block"] ?></option>
                    <?php endif; endforeach ?>
                  </select>

                  <b>YEAR:</b>
                  <select name="yearfilter" class="filter" onchange="this.form.submit()">
                    <option  value="ALL" <?php if($year == $subject["year"]) echo 'selected'?>>ALL</option>
                    <?php $options = []; foreach( $subjects_handling as $subject): if(!in_array($subject["year"],$options)): $options[] = $subject["year"];?>
                      <option  value="<?= $subject["year"] ?>" <?php if($year == $subject["year"]) echo 'selected'?>><?= $subject["year"] ?></option>
                    <?php endif; endforeach ?>
                  </select>

                  <b>PROGRAM:</b>
                  <select name="programfilter" class="filter" onchange="this.form.submit()">
                    <?php $options = []; foreach( $subjects_handling as $subject): if(!in_array($subject["program"],$options)): $options[] = $subject["program"];?>
                      <option  value="<?= $subject["program"] ?>" <?php if($program == $subject["program"]) echo 'selected'?>><?= $subject["program"] ?></option>
                    <?php endif; endforeach ?>
                  </select>
                </div >
                
              </form>

              <div class="row" style=" gap:20px; flex-wrap: wrap; width:100%; ">
                <?php foreach($filtered_subjects_handling as $subject):?>
                  <form method="get" action="<?= BASE_PATH ?>/clearance" class="clearance_categ_box drop_shadow" onclick="this.submit()">
                    <img src="<?= BASE_PATH ?>/View/resources/image2.png" class="clearance_categ_box_upper_part">
                    
                    <div class="clearance_categ_box_buttom_part column">
                      <b style="font-size: 15px;"><?php echo $subject['name']?></b>
                      <b><?php echo $subject['subjectID']." - ".$subject['block'] ?></b>
                    </div>
                    <input type="hidden" name="view" value="SUBJECT">
                    <input type="hidden" name="subjectID" value="<?=  $subject['subjectID'] ?>">
                    <input type="hidden" name="name" value="<?=  $subject['name'] ?>">
                  </form>
                <?php endforeach?>
                
              </div>
              
            
            <?php endif ?>      
            
            
            subject
            <form method="post" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-document">
              <input type="file" name="document"
                    accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                    required>
              <button type="submit">Upload document</button>
              <input type="hidden" name="docs_for" value="addSubjectRequirement">
              <input type="hidden" name="ID" value="CSC112A ">
              <input type="hidden" name="page_uploader" value="<?php echo parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?>">
            </form>
            registrar
            <form method="post" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-document">
              <input type="file" name="document"
                    accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                    required>
              <button type="submit">Upload document</button>
              <input type="hidden" name="docs_for" value="addFacultyRequirement">
              <input type="hidden" name="ID" value="1">
              <input type="hidden" name="page_uploader" value="<?php echo parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?>">
            </form>
            faculty
            <form method="post" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-document">
              <input type="file" name="document"
                    accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                    required>
              <button type="submit">Upload document</button>
              <input type="hidden" name="docs_for" value="addOfficeRequirement">
              <input type="hidden" name="ID" value="1">
              <input type="hidden" name="page_uploader" value="<?php echo parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?>">
            </form>

          
            
          <?php elseif($student_view == "OFFICE"): ?>
          <?php elseif($student_view == "SUBJECT"): $students_for_clearance = getStudentsForSubjectClearance($department,$_GET['subjectID'],$block,$year,$acadyear)?>
            
            <?php //var_dump($department." ".$_GET['subjectID']." ".$block." ".$year." ".$acadyear) ?>
            <?php //var_dump($students_for_clearance) ?>
            <?php if(!isset($_GET['edit'])): ?>
              <div class="row" style="justify-content: space-between; align-items:center">
                <b class="view_title"><?= $_GET['subjectID'].' - '.$_GET['name'] ?>  </b>
                <a href="<?= BASE_PATH ?>/clearance" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
              </div>
              <div>
                <hr>
              </div>
              <div class="updates_frame2  ">
                <table class="updates_table">
                  <thead style="position: relative;z-index: 1;">
                    <tr>
                      <th class="col_name">ID</th>
                      <th class="col_about">NAME</th>
                      <th class="col_date">DATE</th>
                      <th class="col_status">STATUS</th>
                      <th class="col_status">ACTION</th>
                    </tr>
                  </thead>
                  <tbody class="updates_records">
                    <?php foreach ($students_for_clearance as $row): ?>
                      <tr onclick="toggleRemark(this)" class="update_record">
                        <td title="<?= htmlspecialchars($row['studentID']) ?>">
                          <?= htmlspecialchars($row['studentID']) ?>
                        </td>
                        <td title="<?= htmlspecialchars($row['student_name']) ?>">
                          <?= htmlspecialchars($row['student_name']) ?>
                        </td>
                        <td><?= htmlspecialchars($row['date_cleared']) ?></td>
                        <td>
                          <span class="status_badge <?= statusClass($row['status']) ?>">
                            <?= htmlspecialchars(strtoupper($row['status'])) ?>
                          </span>
                          <div class="remark" >
                            <b>REMARKS</b>
                            <p><?=  $row['remarks'] ?> </p>
                          </div>
                        </td>
                        <td>
                          
                          <div>
                            <a href="<?= BASE_PATH ?>/clearance?view=SUBJECT&subjectID=<?= $_GET['subjectID']?>&name=<?=  $_GET['name']?>&edit=1&studentID=<?= $row['studentID'] ?>"><button type="submit">EDIT</button></a>
                          </div>
                        </td>
                        
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              
              <div class="row fileupload_buttom">
                <a href="<?= BASE_PATH ?>/clearance?view=SUBJECT&subjectID=<?= $_GET['subjectID']?>&name=<?=  $_GET['name']?>&upload=1">
                  <button type="button">UPLOAD FILE</button>
                </a>

                <a href="<?= BASE_PATH ?>/clearance?view=SUBJECT&subjectID=<?= $_GET['subjectID']?>&name=<?=  $_GET['name']?>&uploaded=1">
                  <button type="button">VIEW UPLOADS</button>
                </a>

                <?php if(isset($_GET['upload']) && ($_GET['upload'] == 1)): ?>
                  <form class="uploads_container upload_container" method="POST" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-document">
                    <div class="row upload_header">
                      <b>YOUR FILES</b>
                      <a href="<?= BASE_PATH ?>/clearance?view=SUBJECT&subjectID=<?= $_GET['subjectID']?>&name=<?=  $_GET['name']?>">
                        <img src="<?= BASE_PATH ?>/View/resources/Close.png" alt="Close" class="back_buttons">
                      </a>
                    </div>
                    

                    <div class="upload_body">
                      <hr>
                      <div class="files_section">
                        <h3>Files Uploaded</h3>
                        <p class="no_files">No files attached yet.</p>
                        <ul class="file_list"></ul>
                      </div>
                      <hr>
                    </div>

                    <div class="row upload_actions">
                      <label for="document" class="pill_btn">ATTACH FILE</label>
                      <button type="submit" class="pill_btn">SUBMIT</button>

                      <input type="file" id="document" name="document"  hidden
                            accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                    </div>
                    <input type="hidden" name="docs_for" value="addSubjectRequirement">
                    <input type="hidden" name="ID" value="<?=$_GET['subjectID'] ?>">
                    <input type="hidden" name="page_uploader" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                  </form>


                <?php  elseif(isset($_GET['uploaded']) && ($_GET['uploaded']  == 1)): $uploaded_files = getFacultyUploadedRequirements($_GET['subjectID'],$acadyear);?>
                      <div class="uploads_container uploaded_container">
                          <div class="row"  style="justify-content: space-between;">
                              <b>UPLOADED FILES</b>
                              <a href="<?= BASE_PATH ?>/clearance?view=SUBJECT&subjectID=<?= $_GET['subjectID']?>&name=<?=  $_GET['name']?>">
                                <img src="<?= BASE_PATH?>/View/resources/Close.png" alt="" class="back_buttons"></a>
                          </div>
                          <hr>
                          <div class="column" style="gap:20px;  " >
                            <?php foreach($uploaded_files as $requirement): if ($requirement["file_name"] === null) continue;?>
                              <div  class="row uploaded_file_container"  >
                                <div class="row" style="align-items:center;">
                                  <img src="<?= BASE_PATH?>/View/resources/Document.png" alt="" class="file_icons">
                                  <b><?= $requirement["file_name"]?></b>
                                </div>
                                
                                <b><?= $requirement['file_size'] ?></b>
                                <!-- <div class="row" style="align-items:center;">
                                  <a href=""><img src="<= BASE_PATH?>/View/resources/Eye.png" alt="" class="file_icons"></a>
                                  <a href=""><img src="<= BASE_PATH?>/View/resources/Download.png" alt="" class="file_icons"></a>
                                </div> -->
                                <div class="row" style="align-items:center;">
                                  <?php if ($requirement['mime_type'] === 'application/pdf'): ?>
                                    <a href="<?= BASE_PATH ?>/view-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearance&amp;categ=S" target="_blank">
                                      <img src="<?= BASE_PATH ?>/View/resources/Eye.png" alt="View" class="file_icons">
                                    </a>
                                  <?php endif ?>
                                  
                                  <form action="<?= BASE_PATH ?>/delete-document" method="post" onsubmit="return confirm('Delete this file? This cannot be undone.')">
                                    <input type="hidden" name="categ" value="S">
                                    <input type="hidden" name="ID" value="<?= (int) $requirement['ID'] ?>">
                                    <input type="hidden" name="page_uploader" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

                                    <button type="submit" style="background-color: transparent;border:0">
                                      <img src="<?= BASE_PATH ?>/View/resources/Trash.png" alt="Download" class="file_icons"></button>
                                  </form>
                                  
                                </div>
                              </div>

                            <?php endforeach ?>
                          </div>
                      </div>

              
                <?php endif ?>

              </div>
            <?php elseif(isset($_GET['edit']) && ($_GET['edit'] == 1)): $students_for_clearance = (editStudentsForSubjectClearance($department,$_GET['subjectID'],$block,$year,$acadyear, $_GET['studentID']))[0]?>
                   
                <?php var_dump($department.$_GET['subjectID'].$block.$year.$acadyear. $_GET['studentID']) ?>
                <div class="row" style="justify-content: space-between; align-items:center">
                  <b class="view_title"><?= $_GET['subjectID'].' - '.$_GET['name'] ?>  </b>
                  <a href="<?= BASE_PATH ?>/clearance?view=SUBJECT&subjectID=<?= $_GET['subjectID']?>&name=<?=  $_GET['name']?>" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
                </div>
                <div>
                  <hr>
                </div>
                <div class="row" style="gap: 20px;"><?php $uploaded_files = getSubjectRequireMents($_GET['subjectID'],$acadyear,$students_for_clearance['studentID']); ?>
                    <div class="column"  style="gap: 20px;">
                      <div class="student_clearance_panel">
                        <h3>STUDENT INFO:</h3>
                        <div class="row" style="gap: 20px; ">
                          <div class="column" style="gap: 10px; ">
                            <span>ID:</span>
                            <span>NAME:</span>
                            <span>COURSE-BLOCK:</span>     
                          </div>
                          <div class="column" style="gap: 10px;">
                            <b> <?= '&emsp;&emsp;'.strtoupper($students_for_clearance['studentID']) ?>  </b>
                            <b> <?= '&emsp;&emsp;'.strtoupper($students_for_clearance['student_name']) ?>  </b>
                            <b> <?= '&emsp;&emsp;'.strtoupper($students_for_clearance['program'].' - '.strtoupper($students_for_clearance['block'])) ?> </b>             
                                     
                          </div>
                        </div>
                      </div>
                      <div class="student_clearance_panel">
                        <h3>UPLOADED REQUIMENTS:</h3>
                        <div class="column subject_uploaded_file_container" style="gap:20px;  " >
                          <?php foreach($uploaded_files as $requirement): if ($requirement["file_name"] === null) continue;?>
                            <div  class="row uploaded_file_container"  >
                              <div class="row" style="align-items:center;">
                                <img src="<?= BASE_PATH?>/View/resources/Document.png" alt="" class="file_icons">
                                <b><?= $requirement["file_name"]?></b>
                              </div>
                              
                              <b><?= $requirement['file_size'] ?></b>
                              <!-- <div class="row" style="align-items:center;">
                                <a href=""><img src="<= BASE_PATH?>/View/resources/Eye.png" alt="" class="file_icons"></a>
                                <a href=""><img src="<= BASE_PATH?>/View/resources/Download.png" alt="" class="file_icons"></a>
                              </div> -->
                              <div class="row" style="align-items:center;">
                                <?php if ($requirement['mime_type'] === 'application/pdf'): ?>
                                  <a href="<?= BASE_PATH ?>/view-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearanceSigning&amp;categ=S&amp;studentID=<?= $students_for_clearance['studentID'] ?>" target="_blank">
                                    <img src="<?= BASE_PATH ?>/View/resources/Eye.png" alt="View" class="file_icons">
                                  </a>
                                <?php endif ?>
                                
                                <a href="<?= BASE_PATH ?>/download-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearanceSigning&amp;categ=S&amp;download=1&amp;studentID=<?= $students_for_clearance['studentID']?>">
                                  <img src="<?= BASE_PATH ?>/View/resources/Download.png" alt="Download" class="file_icons">
                                </a>
                                
                              </div>
                            </div>

                          <?php endforeach ?>
                        </div>                    
                      </div>  
                      <div class="student_clearance_panel">
                        <h3>PROJECTS:</h3>
                      </div>                 
                    </div>  
                    
                    <div class="column"  style="gap: 20px;">
                      <div class="student_clearance_panel">
                        <h3>GRADES:</h3>
                        <div class="column" style="gap:20px;  " >
                          <div class="row" style="gap: 10px; justify-content:space-between; max-width:200px"><b>MIDTERM: </b><b><?php echo ($students_for_clearance['midterm_grade'] ?? 'N/A') ?></b></div>
                          <div class="row" style="gap: 10px; justify-content:space-between; max-width:200px"><b>FINALS: </b><b><?php echo ($students_for_clearance['final_grade']) ?? 'N/A'?>  </b></div>
                          
                          
                        </div>                    
                      </div>
                      <form method="post" action="<?= BASE_PATH ?>/update_clearance"   class="column student_clearance_panel" style="gap: 20px; ">
                        REMARKS:
                        <textarea name="remarks" id="" style="min-width:500px; max-width:650px; height:150px; padding:20px" class="filter" ><?= htmlspecialchars($students_for_clearance['remarks'] ?? '')?>
                        </textarea>
                        STATUS:<?php $clearance_status = $students_for_clearance['status'] ?? 'NOT YET CLEARED'; ?>
                        <select name="clearance_status_filter" class="filter" onchange="this.style.color = this.options[this.selectedIndex].style.color"  style="color: <?php 
                              {echo (strtoupper($clearance_status) === 'CLEARED') ? 'green' : 
                                  ((strtoupper($clearance_status) === 'NOT YET CLEARED') ? 'yellow' : 
                                  ((strtoupper($clearance_status) === 'REJECT') ? 'red' : 'white')); }
                            ?>;">

                          <option value="CLEARED" style="color:green" <?php if(strtoupper($clearance_status) === 'CLEARED') echo 'selected'  ?>>CLEARED</option>
                          <option value="NOT YET CLEARED" style="color:yellow" <?php if(strtoupper($clearance_status) === 'NOT YET CLEARED') echo 'selected'  ?>>NOT YET CLEARED</option>
                          <option value="REJECT" style="color:red" <?php if(strtoupper($clearance_status) === 'REJECT') echo 'selected'  ?> >REJECT</option>
                        </select>

                        <input type="hidden" name="clearance_categ" value="<?= $student_view ?>">
                        <input type="hidden" name="clearanceID" value="<?= $students_for_clearance['subject_clearanceID'] ?? '' ?>">
                        <input type="hidden" name="enrolled_subID" value="<?= $students_for_clearance['enrolled_subjectID'] ?>">
                        <input type="hidden" name="page_uploader" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">          
                        <button type="submit">SAVE</button>
                      </form>   
                    </div>
                </div>
                
                
                
            <?php endif ?>

          <?php elseif($student_view == "FACULTY"): 


                $students_for_faculty_clearance = getStudentsForFacultyClearance(); 
                $students_for_clearance = getStudentsForFacultyClearance($Fblock,$Facadyear,$FstudentID)
                ?>

                <?php //var_dump($students_for_clearance) ?>
                <?php //var_dump(getFacultyRequirements($acadyear,$_SESSION['real_userID'],$_GET['studentID'])) ?>
            <?php if(!isset($_GET['edit'])): ?>
              <div class="row" style="justify-content: space-between; align-items:center">
                <b class="view_title"><?= strtoupper($_SESSION['role']).' - '.($_SESSION['program'] ?? $_SESSION['department'])?>  </b>
                <a href="<?= BASE_PATH ?>/clearance" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
              </div>

              <form action="<?= BASE_PATH ?>/clearance" method="get">
               <div class="row" style="gap: 20px; align-items:center; border-bottom: 2px solid white; padding-bottom: 20px;" >

                  <b>BLOCK:</b>
                  <select name="Fblockfilter" class="filter" onchange="this.form.submit()">
                    <option  value="ALL" <?php if($Fblock === "ALL") echo 'selected'?>>ALL</option>
                    <?php $options = []; foreach( $students_for_faculty_clearance as $subject): if(!in_array($subject["block"],$options)): $options[] = $subject["block"];?>
                      <option  value="<?= $subject["block"] ?>" <?php if($Fblock == $subject["block"]) echo 'selected'?>><?= $subject["block"] ?></option>
                    <?php endif; endforeach ?>
                  </select>

                  <b>ACAD-YEAR:</b>
                  <select name="Facadyearfilter" class="filter" onchange="this.form.submit()">
                    <?php $options = []; foreach( $students_for_faculty_clearance as $subject): if(!in_array($subject["acad_year_sem"],$options)): $options[] = $subject["acad_year_sem"];?>
                      <option  value="<?= $subject["acad_year_sem"] ?>" <?php if($Facadyear == $subject["acad_year_sem"]) echo 'selected'?>><?= $subject["acad_year_sem"] ?></option>
                    <?php endif; endforeach ?>
                  </select>
                </div >
                <input type="hidden" name="view" value="FACULTY">
              </form>
              <!-- <div>
                <hr>
              </div> -->
              <div class="updates_frame2  ">
                <table class="updates_table">
                  <thead style="position: relative;z-index: 1;">
                    <tr>
                      <th class="col_name">ID</th>
                      <th class="col_about">NAME</th>
                      <th class="col_date">DATE</th>
                      <th class="col_status">STATUS</th>
                      <th class="col_status">ACTION</th>
                    </tr>
                  </thead>
                  <tbody class="updates_records">
                    <?php foreach ($students_for_clearance as $row): ?>
                      <tr onclick="toggleRemark(this)" class="update_record">
                        <td title="<?= htmlspecialchars($row['studentID']) ?>">
                          <?= htmlspecialchars($row['studentID']) ?>
                        </td>
                        <td title="<?= htmlspecialchars($row['student_name']) ?>">
                          <?= htmlspecialchars($row['student_name']) ?>
                        </td>
                        <td><?= htmlspecialchars($row['date_cleared']) ?></td>
                        <td>
                          <span class="status_badge <?php statusClass($row['status']) ?>">
                            <?= htmlspecialchars(strtoupper($row['status'])); ?>
                          </span>
                        </td>
                        <td>
                          
                          <div>
                            <a href="<?= BASE_PATH ?>/clearance?view=FACULTY&studentID=<?= $row['studentID']?>&edit=1"><button type="submit">EDIT</button></a>
                          </div>
                        </td>
                        
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>


            <?php elseif(isset($_GET['edit']) && ($_GET['edit'] == 1)): $students_for_clearance = getStudentsForFacultyClearance($block,$acadyear,$_GET['studentID'])[0]?>
                      <?php //var_dump($students_for_clearance) ?>

                <div class="row" style="justify-content: space-between; align-items:center">
                  <b class="view_title"><?= strtoupper($_SESSION['role']).' - '.($_SESSION['program'] ?? $_SESSION['department']) ?>  </b>
                  <a href="<?= BASE_PATH ?>/clearance?view=FACULTY" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
                </div>
                <div>
                  <hr>
                </div>
                <div class="row" style="gap: 20px;"><?php $uploaded_files = getFacultyRequirements($acadyear,$_SESSION['real_userID'],$_GET['studentID']); ?>
                    <div class="column"  style="gap: 20px;">
                      <div class="student_clearance_panel">
                        <h3>STUDENT INFO:</h3>
                        <div class="row" style="gap: 20px; ">
                          <div class="column" style="gap: 10px; ">
                            <span>ID:</span>
                            <span>NAME:</span>
                            <span>COURSE-BLOCK:</span>     
                          </div>
                          <div class="column" style="gap: 10px;">
                            <b> <?= '&emsp;&emsp;'.strtoupper($students_for_clearance['studentID']) ?>  </b>
                            <b> <?= '&emsp;&emsp;'.strtoupper($students_for_clearance['student_name']) ?>  </b>
                            <b> <?= '&emsp;&emsp;'.strtoupper($students_for_clearance['program'].' - '.strtoupper($students_for_clearance['block'])) ?> </b>             
                                     
                          </div>
                        </div>
                      </div>
                      <div class="student_clearance_panel">
                        <h3>UPLOADED REQUIMENTS:</h3>
                        <div class="column subject_uploaded_file_container" style="gap:20px;  " >
                          <?php foreach($uploaded_files as $requirement): if ($requirement["file_name"] === null) continue;?>
                            <div  class="row uploaded_file_container"  >
                              <div class="row" style="align-items:center;">
                                <img src="<?= BASE_PATH?>/View/resources/Document.png" alt="" class="file_icons">
                                <b><?= $requirement["file_name"]?></b>
                              </div>
                              
                              <b><?= $requirement['file_size'] ?></b>
                              <!-- <div class="row" style="align-items:center;">
                                <a href=""><img src="<= BASE_PATH?>/View/resources/Eye.png" alt="" class="file_icons"></a>
                                <a href=""><img src="<= BASE_PATH?>/View/resources/Download.png" alt="" class="file_icons"></a>
                              </div> -->
                              <div class="row" style="align-items:center;">
                                <?php if ($requirement['mime_type'] === 'application/pdf'): ?>
                                  <a href="<?= BASE_PATH ?>/view-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearanceSigning&amp;categ=F&amp;studentID=<?= $students_for_clearance['studentID'] ?>" target="_blank">
                                    <img src="<?= BASE_PATH ?>/View/resources/Eye.png" alt="View" class="file_icons">
                                  </a>
                                <?php endif ?>
                                
                                <a href="<?= BASE_PATH ?>/download-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearanceSigning&amp;categ=F&amp;download=1&amp;studentID=<?= $students_for_clearance['studentID']?>">
                                  <img src="<?= BASE_PATH ?>/View/resources/Download.png" alt="Download" class="file_icons">
                                </a>
                                
                              </div>
                            </div>

                          <?php endforeach ?>
                        </div>                    
                      </div>  
                      <div class="student_clearance_panel column"><?php $subject_clearance_status  = getSubjectClearanceStatus($students_for_clearance['studentID'],"subjects",$acadyear)?>
                        <h3>SUBJECTS:</h3>
                        <div>
                          <hr>
                        </div>
                        <?php //var_dump($subject_clearance_status) ?>
                        <?php foreach($subject_clearance_status as $subject): ?>
                          <div class="row" style="justify-content: space-between;">
                            <b>
                              <?= $subject['name'] ?>
                            </b>
                            <b style="<?php 
                              if(strtoupper($subject['status']) == 'CLEARED') echo 'color : green';
                              elseif(strtoupper($subject['status']) == 'REJECT') echo 'color : red';
                              else  echo 'color : yellow'?>"
                            >
                              <?= $subject['status'] ?>
                            </b>
                            
                          </div>
                        <?php endforeach ?>
                      </div>                 
                    </div>  
                    
                    <div class="column"  style="gap: 20px;">
                      
                      <form method="post" action="<?= BASE_PATH ?>/update_clearance"   class="column student_clearance_panel" style="gap: 20px; ">
                        REMARKS:
                        <textarea name="remarks" id="" style="min-width:500px; max-width:650px; height:150px; padding:20px" class="filter" ><?= htmlspecialchars($students_for_clearance['remarks'] ?? '')?>
                        </textarea>
                        STATUS:<?php $clearance_status = $students_for_clearance['status'] ?? 'NOT YET CLEARED'; ?>
                        <select name="clearance_status_filter" class="filter" onchange="this.style.color = this.options[this.selectedIndex].style.color"  style="color: <?php 
                              {echo (strtoupper($clearance_status) === 'CLEARED') ? 'green' : 
                                  ((strtoupper($clearance_status) === 'NOT YET CLEARED') ? 'yellow' : 
                                  ((strtoupper($clearance_status) === 'REJECT') ? 'red' : 'white')); }
                            ?>;">

                          <option value="CLEARED" style="color:green" <?php if(strtoupper($clearance_status) === 'CLEARED') echo 'selected'  ?>>CLEARED</option>
                          <option value="NOT YET CLEARED" style="color:yellow" <?php if(strtoupper($clearance_status) === 'NOT YET CLEARED') echo 'selected'  ?>>NOT YET CLEARED</option>
                          <option value="REJECT" style="color:red" <?php if(strtoupper($clearance_status) === 'REJECT') echo 'selected'  ?> >REJECT</option>
                        </select>

                        <input type="hidden" name="clearance_categ" value="<?= $student_view ?>">
                        <input type="hidden" name="clearanceID" value="<?= $students_for_clearance['faculty_clearanceID'] ?? '' ?>">
                        <input type="hidden" name="page_uploader" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">          
                        <button type="submit">SAVE</button>
                      </form>
                      <div class="student_clearance_panel">
                        <div class="row" style="justify-content: space-between;">
                            <h3>REQUIREMENTS:</h3>
                        
                          <a href="<?= BASE_PATH ?>/clearance?view=FACULTY&studentID=<?= $_GET['studentID'] ?>&edit=1&search=<?= $search ?>&upload=1&acadyear=<?= $acadyear ?>">
                            <button type="button">ADD FILE</button>
                          </a>        
                        </div>
                        

                        <div class="column subject_uploaded_file_container" style="gap:20px;" >
                          <?php foreach(getUploadedFacultyRequirements($acadyear,$_SESSION['real_userID'],  $_GET['studentID']) as $requirement): if ($requirement["file_name"] === null) continue;?>
                            <div  class="row uploaded_file_container"  >
                              <div class="row" style="align-items:center;">
                                <img src="<?= BASE_PATH?>/View/resources/Document.png" alt="" class="file_icons">
                                <b><?= $requirement["file_name"]?></b>
                              </div>
                              
                              <b><?= $requirement['file_size'] ?></b>
                              <!-- <div class="row" style="align-items:center;">
                                <a href=""><img src="<= BASE_PATH?>/View/resources/Eye.png" alt="" class="file_icons"></a>
                                <a href=""><img src="<= BASE_PATH?>/View/resources/Download.png" alt="" class="file_icons"></a>
                              </div> -->
                              <div class="row" style="align-items:center;">
                                <?php if ($requirement['mime_type'] === 'application/pdf'): ?>
                                  <a href="<?= BASE_PATH ?>/view-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearanceSigning&amp;categ=S&amp;studentID=<?= $students_for_clearance['studentID'] ?>" target="_blank">
                                    <img src="<?= BASE_PATH ?>/View/resources/Eye.png" alt="View" class="file_icons">
                                  </a>
                                <?php endif ?>
                                
                                <a href="<?= BASE_PATH ?>/download-document?id=<?= (int)$requirement['ID'] ?>&amp;docfor=clearanceSigning&amp;categ=S&amp;download=1&amp;studentID=<?= $students_for_clearance['studentID']?>">
                                  <img src="<?= BASE_PATH ?>/View/resources/Download.png" alt="Download" class="file_icons">
                                </a>
                                
                              </div>
                            </div>

                          <?php endforeach ?>
                          
                        </div>                    
                      </div>
                      
                      <?php if(isset($_GET['upload']) && ($_GET['upload'] == 1)): ?>
                      <form class="uploads_container upload_container" method="POST" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-document">
                          <div class="row upload_header">
                            <b>YOUR FILES</b>
                            <a href="<?= BASE_PATH ?>/clearance?view=FACULTY&studentID=<?= $_GET['studentID'] ?>&edit=1&search=<?= $search ?>&acadyear=<?= $acadyear ?>">
                              <img src="<?= BASE_PATH ?>/View/resources/Close.png" alt="Close" class="back_buttons">
                            </a>
                          </div>
                          

                          <div class="upload_body">
                            <hr>
                            <div class="files_section">
                              <h3>Files Uploaded</h3>
                              <p class="no_files">No files attached yet.</p>
                              <ul class="file_list"></ul>
                            </div>
                            <hr>
                          </div>

                          <div class="row upload_actions">
                            <label for="document" class="pill_btn">ATTACH FILE</label>
                            <button type="submit" class="pill_btn">SUBMIT</button>

                            <input type="file" id="document" name="document"  hidden
                                  accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                          </div>
                          <input type="hidden" name="clearance_categ" value="<?= $student_view ?>">
                          <input type="hidden" name="studentID" value="<?= $students_for_clearance['real_studentID'] ?>">
                          <input type="hidden" name="clearanceID" value="<?= $students_for_clearance['faculty_clearanceID'] ?? '' ?>">
                          <input type="hidden" name="enrollmentID" value="<?= $students_for_clearance['enrollmentID'] ?? '' ?>">
                          <input type="hidden" name="docs_for" value="addFacultyRequirement">
                          <input type="hidden" name="ID" value="<?= $students_for_clearance['faculty_clearanceID'] ?? 'none' ?>">
                          <input type="hidden" name="page_uploader" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                        </form>
                        <?php endif ?>
                    </div>
                </div>
            <?php endif ?>
          <?php endif ?>



        <?php endif ?>

        <!-- <form method="post" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-profile">
          <input type="file" name="profile_picture" accept="image/jpeg,image/png,image/webp" required>
          <button type="submit">change profile</button>
        </form>  -->


      </main>
    </div>
  </div>
  <script>
    <?php if ($student_view == "MAIN"): ?>
      
        new Chart(document.getElementById('pie'), {
          type: 'pie',
          data: {
            labels: <?= $labels ?>,
            datasets: [{
              data: <?= $values ?>,
              backgroundColor: ['#7700ff', '#cf00fd', '#00a4fd', '#1eff00']
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              title: {
                display: true,
                text: 'Clearance Status',
                color: '#f8f1f1',
                font: {
                  size: 30,
                  weight: 'bold',
                  family: 'Arial'
                }
              },
              legend: {
                position: 'bottom',
                align: 'start',
                labels: {
                  color: '#ffffff', // legend text color
                  font: {
                    size: 20,
                    family: 'Arial'
                  },
                  padding: 40,
                  usePointStyle: true // round bullets instead of boxes
                }
              },
              tooltip: {
                backgroundColor: '#222', // hover box background
                titleColor: '#fff',
                bodyColor: '#ffd166',
                bodyFont: {
                  size: 14
                },
                padding: 10
              }
            }
          }
        });

        
      
    <?php elseif (in_array($student_view ,["SUBJECT-REQUIREMENTS","FACULTY-REQUIREMENTS", "OFFICE-REQUIREMENTS","SUBJECT","FACULTY","OFFICE"])): ?>
        const input = document.getElementById('document');
        const list = document.querySelector('.file_list');
        const noFiles = document.querySelector('.no_files');
        const form = document.querySelector('.upload_container');

        input.addEventListener('change', () => {
          list.innerHTML = '';
          noFiles.style.display = input.files.length ? 'none' : '';

          Array.from(input.files).forEach(file => {
            const li = document.createElement('li');
            li.textContent = file.name;
            list.appendChild(li);
          });
        });

        form.addEventListener('submit', e => {
          if (!input.files.length) {
            e.preventDefault();
            noFiles.textContent = 'Please attach at least one file.';
          }
        });
    <?php elseif($student_view == "UPDATES"): ?>
      function toggleRemark(row) {
        const remark = row.querySelector('.remark');
        const wasOpen = remark.classList.contains('open');

        document.querySelectorAll('.remark.open')
          .forEach(el => el.classList.remove('open'));

        if (!wasOpen) remark.classList.add('open');
      }
    <?php endif ?>
  </script>
  <?php require_once __DIR__ . "/components/logout.php"; ?>
</body>

</html>