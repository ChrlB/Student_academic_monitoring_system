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
        <!-- <div style="width:600px; height:400px; border:2px solid #444; border-radius:8px; overflow:hidden;">
          <iframe src="serve.php?file=<= urlencode($file) ?>"
                  style="width:100%; height:100%; border:0;"></iframe>
          </div> -->

          <!-- <form method="post" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-document">
              <input type="file" name="document"
                    accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                    required>
              <button type="submit">Upload document</button>
          </form>
        <img src="<?= BASE_PATH ?>/get-profile" width="150" height="150" alt="No profile picture yet"> -->
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
              <a href="<?= BASE_PATH ?>/clearance" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
            </div>
            <div class="updates_frame2  drop_shadow">
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
          <?php elseif ($student_view === "SUBJECTS"): $subjects = getSubjectsClearance($search,$status)?>
            <div class="row" style="justify-content: space-between; align-items:center">
              <b class="view_title">SUBJECTS</b>
              <a href="<?= BASE_PATH ?>/clearance" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
            </div>
            <form class="row" style="gap: 20px;" action="<?= BASE_PATH ?>/clearance" method="get">
              <input type="hidden" name="view" value="SUBJECTS">
              <div class="row searchbar_frame">
                <input type="text" name="search"  placeholder="SEARCH" class="searchbar" value="<?= htmlspecialchars($search === "ALL" ? '':$search) ?>">
                <button type="submit" onclick="this.form.submit()" style="border: 0px;background-color:transparent;">
                    <img src="<?= BASE_PATH?>/View/resources/search.png" alt="" class="back_buttons" style="padding:5px">
                </button>
              </div>
              
              <select name="statusfilter" class="filter" onchange="this.form.submit()">
                <option value="ALL" <?= $status === 'ALL' ? 'selected' : '' ?>>ALL</option>
                <?php $options = []; foreach( getSubjectsClearance() as $subject): if(!in_array($subject["status"],$options)): $options[] = $subject["status"];?>
                  <option  value="<?= $subject["status"] ?>" <?php if($status == $subject["status"]) echo 'selected'?>><?= $subject["status"] ?></option>
                <?php endif; endforeach ?>
              </select>
            </form>
            <div class="column" style="gap: 20px;">
              <?php foreach( $subjects as $subject):?>
                
                <form class="row clearance_box  drop_shadow" action="<?= BASE_PATH ?>/clearance" method="get">
                  <div class="column">
                    <b style="font-size: 50px;"><?= $subject["subjectID"].' - '.$subject["name"] ?></b>
                    <b style="font-size: 30px;"><?= $subject["prof_name"] ?></b>
                    <b style="font-size: 30px;"><?= $subject["room"] ?></b>
                  </div>
                  <div >
                    <input type="hidden" name="view" value="SUBJECT-REQUIREMENTS">
                    <button type="submit" onclick="this.form.submit()" style="background-color: transparent; border:0px;">
                      <img src="<?= BASE_PATH?>/View/resources/openClearance.png" alt="" class="open_button" >
                    </button>
                    <input type="hidden" name="search" value="<?php echo $subject["subjectID"] ?>">
                  </div>

                </form>
              <?php endforeach ?>
            </div> 

          <?php elseif ($student_view === "SUBJECT-REQUIREMENTS"): $subject_info = getSubjectRequireMents($search); ?>
          
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
                  <a href="<?= BASE_PATH ?>/clearance?view=SUBJECT-REQUIREMENTS&search=<?= $search ?>&upload=1">
                    <button type="button">UPLOAD FILE</button>
                  </a>
                  <a href="<?= BASE_PATH ?>/clearance?view=SUBJECT-REQUIREMENTS&search=<?= $search ?>&uploaded=1">
                    <button type="button">VIEW UPLOADS</button>
                  </a>


                  <?php if(isset($_GET['upload']) && ($_GET['upload'] == 1)): ?>
                    <form class="uploads_container upload_container" method="POST" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-document">
                      <div class="row upload_header">
                        <b>YOUR FILES</b>
                        <a href="<?= BASE_PATH ?>/clearance?view=SUBJECT-REQUIREMENTS&search=<?= $search ?>">
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
                      <input type="hidden" name="page_uploader" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    </form>

                  <?php  elseif(isset($_GET['uploaded']) && ($_GET['uploaded']  == 1)): $uploaded_files = getUploadedSubjectRequireMents($search);?>
                    <div class="uploads_container uploaded_container">
                        <div class="row"  style="justify-content: space-between;">
                            <b>UPLOADED FILES</b>
                            <a href="<?= BASE_PATH ?>/clearance?view=SUBJECT-REQUIREMENTS&search=<?= $search ?>">
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

                

          <?php elseif ($student_view === "FACULTY"): $faculties = getFacultyClearance($search,$status);?>
            <div class="row" style="justify-content: space-between; align-items:center">
              <b class="view_title">FACULTY</b>
              <a href="<?= BASE_PATH ?>/clearance" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
            </div>
            <form class="row" style="gap: 20px;" action="<?= BASE_PATH ?>/clearance" method="get">
              <input type="hidden" name="view" value="FACULTY">
              <div class="row searchbar_frame">
                <input type="text" name="search"  placeholder="SEARCH" class="searchbar" value="<?= htmlspecialchars($_GET["search"] ??  '') ?>">
                <button type="submit" onclick="this.form.submit()" style="border: 0px;background-color:transparent;">
                    <img src="<?= BASE_PATH?>/View/resources/search.png" alt="" class="back_buttons" style="padding:5px">
                </button>
              </div>
              
              <select name="statusfilter" class="filter" onchange="this.form.submit()">
                <option value="ALL" <?= $status === 'ALL' ? 'selected' : '' ?>>ALL</option>
                <?php $options = []; foreach( getFacultyClearance() as $faculty): if(!in_array($faculty["status"],$options)): $options[] = $faculty["status"];?>
                  <option  value="<?= $faculty["status"] ?>" <?php if($status == $faculty["status"]) echo 'selected'?>><?= $faculty["status"] ?></option>
                <?php endif; endforeach ?>
              </select>
            </form>
            <div class="column" style="gap: 20px;">
              <?php foreach( $faculties as $faculty):?>
                
                <form class="row clearance_box" action="<?= BASE_PATH ?>/clearance" method="get">
                  <div class="column">
                    <b style="font-size: 50px;"><?= strtoupper($faculty["role"]) ?></b>
                    <b style="font-size: 30px;"><?= $faculty["prof_name"] ?></b>
                  </div>
                  <div >
                    <button type="submit" onclick="this.form.submit()" style="background-color: transparent; border:0px;">
                      <img src="<?= BASE_PATH?>/View/resources/openClearance.png" alt="" class="open_button" >
                    </button>
                    <input type="hidden" name="view" value="FACULTY-REQUIREMENTS">
                    <input type="hidden" name="search" value="<?php echo $faculty["facultyID"] ?>">
                  </div>
                </form>
              <?php endforeach ?>
            </div> 

          <?php elseif ($student_view === "FACULTY-REQUIREMENTS"): $faculty_info = getFacultyRequireMents($search); ?>
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
                  <a href="<?= BASE_PATH ?>/clearance?view=FACULTY-REQUIREMENTS&search=<?= $search ?>&upload=1">
                    <button type="button">UPLOAD FILE</button>
                  </a>
                  <a href="<?= BASE_PATH ?>/clearance?view=FACULTY-REQUIREMENTS&search=<?= $search ?>&uploaded=1">
                    <button type="button">VIEW UPLOADS</button>
                  </a>


                  <?php if(isset($_GET['upload']) && ($_GET['upload'] == 1)): ?>
                    <form class="uploads_container upload_container" method="POST" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-document">
                      <div class="row upload_header">
                        <b>YOUR FILES</b>
                        <a href="<?= BASE_PATH ?>/clearance?view=FACULTY-REQUIREMENTS&search=<?= $search ?>">
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

                  <?php  elseif(isset($_GET['uploaded']) && ($_GET['uploaded']  == 1)): $uploaded_files = getUploadedFacultyRequireMents($search);?>
                    <div class="uploads_container uploaded_container">
                        <div class="row"  style="justify-content: space-between;">
                            <b>UPLOADED FILES</b>
                            <a href="<?= BASE_PATH ?>/clearance?view=FACULTY-REQUIREMENTS&search=<?= $search ?>">
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
            
          <?php elseif ($student_view === "OFFICE"): $offices = getOfficeClearance($search,$status);?>
            <div class="row" style="justify-content: space-between; align-items:center">
              <b class="view_title">OFFICE</b>
              <a href="<?= BASE_PATH ?>/clearance" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
            </div>
            <form class="row" style="gap: 20px;" action="<?= BASE_PATH ?>/clearance" method="get">
              <input type="hidden" name="view" value="OFFICE">
              <div class="row searchbar_frame">
                <input type="text" name="search"  placeholder="SEARCH" class="searchbar" value="<?= htmlspecialchars($_GET["search"] ??  '') ?>">
                <button type="submit" onclick="this.form.submit()" style="border: 0px;background-color:transparent;">
                    <img src="<?= BASE_PATH?>/View/resources/search.png" alt="" class="back_buttons" style="padding:5px">
                </button>
              </div>
              
              <select name="statusfilter" class="filter" onchange="this.form.submit()">
                <option value="ALL" <?= $status === 'ALL' ? 'selected' : '' ?>>ALL</option>
                <?php $options = []; foreach( getOfficeClearance() as $faculty): if(!in_array($faculty["status"],$options)): $options[] = $faculty["status"];?>
                  <option  value="<?= $faculty["status"] ?>" <?php if($status == $faculty["status"]) echo 'selected'?>><?= $faculty["status"] ?></option>
                <?php endif; endforeach ?>
              </select>
            </form>
            <div class="column" style="gap: 20px;">
              <?php foreach( $offices as $office):?>
                
                <form class="row clearance_box" action="<?= BASE_PATH ?>/clearance" method="get">
                  <div class="column">
                    <b style="font-size: 50px;"><?= strtoupper($office["name"]) ?></b>
                    <b style="font-size: 30px;"><?= strtoupper($office["prof_name"]) ?></b>
                  </div>
                  <div >
                    <button type="submit" onclick="this.form.submit()" style="background-color: transparent; border:0px;">
                      <img src="<?= BASE_PATH?>/View/resources/openClearance.png" alt="" class="open_button" >
                    </button>
                    <input type="hidden" name="view" value="OFFICE-REQUIREMENTS">
                    <input type="hidden" name="search" value="<?php echo $office["officeID"] ?>">
                  </div>
                </form>
              <?php endforeach ?>
            </div> 
          
          
          <?php elseif ($student_view === "OFFICE-REQUIREMENTS"): $office_info = getOfficeRequireMents($search); ?>
            <div class="row" style="justify-content: space-between; align-items:center">
              <b class="view_title">OFFICE</b>
               <a href="<?= BASE_PATH ?>/clearance?view=OFFICE" ><img src="<?= BASE_PATH?>/View/resources/return.png" alt="← back" class="back_buttons"></a>
            </div>
            <div class="row subject_container">
              <div class="column">
                <b>NAME: <?= $office_info[0]["name"] ?></b>
                <b>ROLE: <?= $office_info[0]["prof_name"]?></b>
                
                
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
                <?php foreach($office_info as $requirement): if ($requirement["file_name"] === null) continue;?>
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
                  <a href="<?= BASE_PATH ?>/clearance?view=OFFICE-REQUIREMENTS&search=<?= $search ?>&upload=1">
                    <button type="button">UPLOAD FILE</button>
                  </a>
                  <a href="<?= BASE_PATH ?>/clearance?view=OFFICE-REQUIREMENTS&search=<?= $search ?>&uploaded=1">
                    <button type="button">VIEW UPLOADS</button>
                  </a>


                  <?php if(isset($_GET['upload']) && ($_GET['upload'] == 1)): ?>
                    <form class="uploads_container upload_container" method="POST" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-document">
                      <div class="row upload_header">
                        <b>YOUR FILES</b>
                        <a href="<?= BASE_PATH ?>/clearance?view=OFFICE-REQUIREMENTS&search=<?= $search ?>">
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

                  <?php  elseif(isset($_GET['uploaded']) && ($_GET['uploaded']  == 1)): $uploaded_files = getUploadedOfficeRequireMents($search);?>
                    <div class="uploads_container uploaded_container">
                        <div class="row"  style="justify-content: space-between;">
                            <b>UPLOADED FILES</b>
                            <a href="<?= BASE_PATH ?>/clearance?view=OFFICE-REQUIREMENTS&search=<?= $search ?>">
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
          <form method="post" enctype="multipart/form-data" action="<?= BASE_PATH ?>/upload-document">
            <input type="file" name="document"
                  accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                  required>
            <button type="submit">Upload document</button>
            <input type="hidden" name="docs_for" value="addOfficeRequirement">
            <input type="hidden" name="ID" value="1">
            <input type="hidden" name="page_uploader" value="<?php echo parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?>">
          </form>
          <!-- INSERT INTO `subject_clearance_requirements` (`ID`, `enrolled_subjectID`, `fileID`, `file_name`, `file_size`, `uploaded_at`, `uploaded_by`) VALUES (NULL, '', '', '', '', CURRENT_TIMESTAMP, '') -->
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

        
      
    <?php elseif ($student_view == "SUBJECT-REQUIREMENTS" || $student_view == "FACULTY-REQUIREMENTS" || $student_view == "OFFICE-REQUIREMENTS"): ?>
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
    <?php endif ?>
  </script>
  <?php require_once __DIR__ . "/components/logout.php"; ?>
</body>

</html>