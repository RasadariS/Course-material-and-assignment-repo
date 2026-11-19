(function () {
  var page = document.currentScript.getAttribute('data-page');
  var initializers = {
    'admin/adminprofile.php': function () {
      var footerYear = document.getElementById('footerYear');
      if (footerYear) footerYear.textContent = new Date().getFullYear();
    },
    // Form/page: Administrator signup form
    'admin/signup.php': function () {
    var accountType = document.getElementById('accountType');
    var photoUpload = document.getElementById('photoUpload');
    var profilePhoto = document.getElementById('profilePhoto');
    var photoPreviewUrl = '';
    var roleFields = {
      student: {
        section: document.getElementById('studentFields'),
        inputs: [
          document.getElementById('registrationNumber'),
          document.getElementById('intakeYear'),
          document.getElementById('semesterEnrolled')
        ]
      },
      instructor: {
        section: document.getElementById('instructorFields'),
        inputs: [
          document.getElementById('employeeCode'),
          document.getElementById('department')
        ]
      }
    };

    function updateRoleFields() {
      Object.keys(roleFields).forEach(function (role) {
        var group = roleFields[role];
        if (!group) return;
        var isSelected = accountType.value === role;
        group.section.hidden = !isSelected;
        group.inputs.forEach(function (input) {
          input.required = isSelected;
          input.disabled = !isSelected;
          if (!isSelected) input.value = '';
        });
      });
    }

    accountType.addEventListener('change', updateRoleFields);
    updateRoleFields();

    photoUpload.addEventListener('change', function () {
      if (photoPreviewUrl) URL.revokeObjectURL(photoPreviewUrl);
      photoPreviewUrl = '';
      if (photoUpload.files.length) {
        photoPreviewUrl = URL.createObjectURL(photoUpload.files[0]);
        profilePhoto.src = photoPreviewUrl;
      } else {
        profilePhoto.src = '../assets/images/person-fill.svg';
      }
    });

    document.querySelector('form').addEventListener('submit', function (event) {
      var password = document.getElementById('password');
      var confirmPassword = document.getElementById('confirmPassword');
      confirmPassword.setCustomValidity(
        password.value === confirmPassword.value ? '' : 'Passwords do not match.'
      );
      if (!this.checkValidity()) {
        event.preventDefault();
        this.reportValidity();
      }
    });

    function loadPartial(id, file, done) {
      fetch(file)
        .then(function (response) {
          if (!response.ok) throw new Error('HTTP error! status: ' + response.status);
          return response.text();
        })
        .then(function (html) {
          document.getElementById(id).innerHTML = html;
          if (done) done();
        })
        .catch(function (error) {
          console.error('Error loading ' + file + ':', error);
        });
    }

    loadPartial('header-placeholder', '../layouts/header.php');
    loadPartial('footer-placeholder', '../layouts/footer.php', function () {
      var footerYear = document.getElementById('footerYear');
      if (footerYear) footerYear.textContent = new Date().getFullYear();
    });
  
    },
    // Form/page: User privilege form
    'admin/userprevilage.php': function () {
    (function () {
      var storageKey = 'dtetHtmlFormAccessV1';
      var checkboxes = document.querySelectorAll('.form-access-checkbox');
      var pageStatus = document.getElementById('pageStatus');

      function accessKey(checkbox) {
        return checkbox.getAttribute('data-form') + ':' + checkbox.getAttribute('data-role');
      }

      function saveAccess() {
        var access = {};
        checkboxes.forEach(function (checkbox) {
          access[accessKey(checkbox)] = checkbox.checked;
        });
        try {
          localStorage.setItem(storageKey, JSON.stringify(access));
          pageStatus.textContent = 'Form access selections saved in this browser.';
          pageStatus.className = 'alert alert-success';
        } catch (error) {
          pageStatus.textContent = 'Could not save form access selections in this browser.';
          pageStatus.className = 'alert alert-danger';
          console.error('Could not save form access selections:', error);
        }
      }

      try {
        var savedAccess = localStorage.getItem(storageKey);
        if (savedAccess) {
          var access = JSON.parse(savedAccess);
          if (!access || typeof access !== 'object') throw new Error('Saved form access settings have an invalid format.');
          checkboxes.forEach(function (checkbox) {
            var key = accessKey(checkbox);
            if (typeof access[key] === 'boolean') checkbox.checked = access[key];
          });
        }
      } catch (error) {
        pageStatus.textContent = 'Saved form access selections could not be loaded. Default selections are shown.';
        pageStatus.className = 'alert alert-warning';
        console.error('Could not load form access selections:', error);
      }

      checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', saveAccess);
      });

      var footerYear = document.getElementById('footerYear');
      if (footerYear) footerYear.textContent = new Date().getFullYear();
    }());
  
    },
    // Form/page: Course details and assignment submission form
    'course/course.php': function () {
  document.querySelectorAll('#courseTabs [data-bs-toggle="tab"]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      document.querySelectorAll('#courseTabs [data-bs-toggle="tab"]').forEach(function (item) {
        item.classList.remove('active');
        item.setAttribute('aria-selected', 'false');
      });

      document.querySelectorAll('.tab-content .tab-pane').forEach(function (pane) {
        pane.classList.remove('show', 'active');
      });

      tab.classList.add('active');
      tab.setAttribute('aria-selected', 'true');
      var targetPane = document.querySelector(tab.getAttribute('data-bs-target'));
      if (targetPane) targetPane.classList.add('show', 'active');
    });
  });

    },
    // Form/page: Course upload form
    'course/courseupload.html': function () {
    [
      { input: document.getElementById('materialFile'), status: document.getElementById('materialFileStatus') },
      { input: document.getElementById('assignmentFile'), status: document.getElementById('assignmentFileStatus') }
    ].forEach(function (fileField) {
      fileField.input.addEventListener('change', function () {
        var file = fileField.input.files[0];
        fileField.status.textContent = file
          ? file.name + ' (' + (file.size / (1024 * 1024)).toFixed(2) + ' MB)'
          : '';
      });
    });

    function loadPartial(id, file, done) {
      fetch(file)
        .then(function (response) {
          if (!response.ok) throw new Error('HTTP error! status: ' + response.status);
          return response.text();
        })
        .then(function (html) {
          document.getElementById(id).innerHTML = html;
          if (done) done();
        })
        .catch(function (error) {
          console.error('Error loading ' + file + ':', error);
        });
    }

    loadPartial('header-placeholder', '../layouts/header.php');
    loadPartial('footer-placeholder', '../layouts/footer.php', function () {
      var year = document.getElementById('footerYear');
      if (year) year.textContent = new Date().getFullYear();
    });
  
    },
    // Form/page: My courses page
    'course/mycourses.html': function () {
    function loadPartial(id, file, done) {
      fetch(file)
        .then(response => {
          if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
          return response.text();
        })
        .then(html => {
          const placeholder = document.getElementById(id);
          placeholder.innerHTML = html;
          const partialUrl = new URL(file, document.baseURI);
          placeholder.querySelectorAll('[src], [href]').forEach(element => {
            ['src', 'href'].forEach(attribute => {
              const value = element.getAttribute(attribute);
              if (value && !/^(?:[a-z]+:|\/|#)/i.test(value)) {
                element.setAttribute(attribute, new URL(value, partialUrl).href);
              }
            });
          });
          if (done) done();
        })
        .catch(error => console.error(`Error loading ${file}:`, error));
    }

    loadPartial('header-placeholder', '../layouts/header.php');
    loadPartial('footer-placeholder', '../layouts/footer.php', function () {
      const footerYear = document.getElementById('footerYear');
      if (footerYear) {
        footerYear.textContent = new Date().getFullYear();
      }
    });
  
    },
    // Form/page: Welcome page
    'index.php': function () {
    const footerYear = document.getElementById('footerYear');
    if (footerYear) footerYear.textContent = new Date().getFullYear();
  
    },
    // Form/page: Instructor enrollment form
    'instructor/instructorenrolement.html': function () {
		(function () {
			var courseStorageKey = 'dtetCourseManagementSchemaV1';
			var courses = [];
			var semesters = [];
			var modules = [];
			var instructors = [];
			var assignedModuleIds = {};
			var pendingModuleIds = {};
			var instructorSelect = document.getElementById('instructorSelect');
			var courseFilter = document.getElementById('courseFilter');
			var semesterFilter = document.getElementById('semesterFilter');
			var moduleList = document.getElementById('moduleList');
			var pageStatus = document.getElementById('pageStatus');

			function showStatus(message, type) {
				pageStatus.textContent = message;
				pageStatus.className = 'alert alert-' + type;
			}

			function clearStatus() {
				pageStatus.textContent = '';
				pageStatus.className = 'alert d-none';
			}

			function addOption(select, value, label) {
				var option = document.createElement('option');
				option.value = value;
				option.textContent = label;
				select.appendChild(option);
			}

			function loadCourseCatalog() {
				var stored = localStorage.getItem(courseStorageKey);
				if (!stored) throw new Error('No course catalog found. Add courses, semesters, and modules in Course Management first.');
				var data = JSON.parse(stored);
				if (!data || !Array.isArray(data.courses) || !Array.isArray(data.semesters) || !Array.isArray(data.modules)) {
					throw new Error('The saved course catalog is not in the expected format.');
				}
				courses = data.courses;
				semesters = data.semesters;
				modules = data.modules;
			}

			function fillCourseFilter() {
				courseFilter.textContent = '';
				addOption(courseFilter, '', 'All courses');
				courses.slice().sort(function (left, right) {
					return String(left.course_name).localeCompare(String(right.course_name));
				}).forEach(function (course) {
					addOption(courseFilter, String(course.course_id), course.course_code + ' - ' + course.course_name);
				});
				courseFilter.disabled = courses.length === 0;
			}

			function fillSemesterFilter() {
				var selectedCourse = courseFilter.value;
				var previousSemester = semesterFilter.value;
				var available = semesters.filter(function (semester) {
					return !selectedCourse || String(semester.course_id) === selectedCourse;
				});
				semesterFilter.textContent = '';
				addOption(semesterFilter, '', 'All semesters');
				available.slice().sort(function (left, right) {
					return Number(left.semester_number) - Number(right.semester_number);
				}).forEach(function (semester) {
					var course = courses.find(function (item) { return String(item.course_id) === String(semester.course_id); });
					var label = 'Semester ' + semester.semester_number + (semester.academic_year ? ' (' + semester.academic_year + ')' : '');
					if (!selectedCourse && course) label = course.course_code + ' - ' + label;
					addOption(semesterFilter, String(semester.semester_id), label);
				});
				semesterFilter.disabled = available.length === 0;
				if (available.some(function (item) { return String(item.semester_id) === previousSemester; })) {
					semesterFilter.value = previousSemester;
				}
			}

			function updateSelectionCount() {
				var count = Object.keys(pendingModuleIds).length;
				document.getElementById('selectionCount').textContent = count + (count === 1 ? ' module selected' : ' modules selected');
				document.getElementById('assignButton').disabled = !instructorSelect.value || count === 0;
				document.getElementById('clearSelection').disabled = count === 0;
			}

			function createEmptyState(message) {
				var state = document.createElement('div');
				state.className = 'empty-state';
				state.textContent = message;
				moduleList.appendChild(state);
			}

			function renderModules() {
				moduleList.textContent = '';
				var selectedCourse = courseFilter.value;
				var selectedSemester = semesterFilter.value;
				var visibleModules = modules.filter(function (module) {
					var semester = semesters.find(function (item) { return String(item.semester_id) === String(module.semester_id); });
					if (!semester) return false;
					return (!selectedCourse || String(semester.course_id) === selectedCourse) &&
						(!selectedSemester || String(semester.semester_id) === selectedSemester);
				});

				if (!instructorSelect.value) {
					createEmptyState('Select an instructor to view current assignments.');
					updateSelectionCount();
					return;
				}
				if (!visibleModules.length) {
					createEmptyState('No modules match the selected course and semester.');
					updateSelectionCount();
					return;
				}

				var grouped = {};
				visibleModules.forEach(function (module) {
					var semester = semesters.find(function (item) { return String(item.semester_id) === String(module.semester_id); });
					var course = courses.find(function (item) { return String(item.course_id) === String(semester.course_id); });
					if (!course) return;
					var key = String(course.course_id) + ':' + String(semester.semester_id);
					if (!grouped[key]) grouped[key] = { course: course, semester: semester, modules: [] };
					grouped[key].modules.push(module);
				});

				Object.keys(grouped).sort(function (left, right) {
					var leftGroup = grouped[left];
					var rightGroup = grouped[right];
					var courseOrder = String(leftGroup.course.course_name).localeCompare(String(rightGroup.course.course_name));
					return courseOrder || Number(leftGroup.semester.semester_number) - Number(rightGroup.semester.semester_number);
				}).forEach(function (key) {
					var group = grouped[key];
					var courseSection = document.createElement('section');
					courseSection.className = 'course-group';
					var courseHeading = document.createElement('h3');
					courseHeading.className = 'course-heading mb-0';
					courseHeading.textContent = group.course.course_code + ' - ' + group.course.course_name;
					courseSection.appendChild(courseHeading);

					var semesterGroup = document.createElement('div');
					semesterGroup.className = 'semester-group';
					var semesterHeading = document.createElement('h4');
					semesterHeading.className = 'semester-heading mb-2';
					semesterHeading.textContent = 'Semester ' + group.semester.semester_number +
						(group.semester.academic_year ? ' · ' + group.semester.academic_year : '');
					semesterGroup.appendChild(semesterHeading);

					group.modules.sort(function (left, right) {
						return String(left.module_name).localeCompare(String(right.module_name));
					}).forEach(function (module) {
						var moduleRow = document.createElement('div');
						moduleRow.className = 'module-row';
						var checkbox = document.createElement('input');
						checkbox.type = 'checkbox';
						checkbox.id = 'module-' + module.module_id;
						checkbox.value = String(module.module_id);
						var isAssigned = Boolean(assignedModuleIds[String(module.module_id)]);
						checkbox.checked = isAssigned || Boolean(pendingModuleIds[String(module.module_id)]);
						checkbox.disabled = isAssigned;
						checkbox.addEventListener('change', function () {
							if (checkbox.checked) pendingModuleIds[checkbox.value] = true;
							else delete pendingModuleIds[checkbox.value];
							updateSelectionCount();
						});

						var label = document.createElement('label');
						label.setAttribute('for', checkbox.id);
						label.appendChild(document.createTextNode((module.module_code ? module.module_code + ' - ' : '') + module.module_name));
						if (module.description) {
							var description = document.createElement('span');
							description.className = 'module-description';
							description.textContent = module.description;
							label.appendChild(description);
						}
						moduleRow.appendChild(checkbox);
						moduleRow.appendChild(label);
						if (isAssigned) {
							var assignedState = document.createElement('span');
							assignedState.className = 'module-state';
							assignedState.textContent = 'Assigned' + (assignedModuleIds[String(module.module_id)].assigned_date
								? ' · ' + assignedModuleIds[String(module.module_id)].assigned_date
								: '');
							moduleRow.appendChild(assignedState);
						}
						semesterGroup.appendChild(moduleRow);
					});
					courseSection.appendChild(semesterGroup);
					moduleList.appendChild(courseSection);
				});
				updateSelectionCount();
			}

			function loadInstructors() {
				fetch('../api/admin/instructors.php', {
					credentials: 'same-origin',
					headers: { Accept: 'application/json' }
				})
					.then(function (response) {
						if (!response.ok) throw new Error('Instructor service returned HTTP ' + response.status + '.');
						return response.json();
					})
					.then(function (payload) {
						if (!payload || !Array.isArray(payload.instructors)) throw new Error('The instructor service returned an invalid response.');
						instructors = payload.instructors;
						instructorSelect.textContent = '';
						addOption(instructorSelect, '', 'Select an instructor profile');
						instructors.forEach(function (instructor) {
							if (instructor.instructor_id === undefined || !instructor.full_name) return;
							var label = instructor.employee_code
								? instructor.employee_code + ' - ' + instructor.full_name
								: instructor.full_name;
							addOption(instructorSelect, String(instructor.instructor_id), label);
						});
						instructorSelect.disabled = instructors.length === 0;
						if (!instructors.length) {
							showStatus('No instructor profiles were returned. Create instructor profiles before assigning modules.', 'warning');
						} else {
							clearStatus();
						}
					})
					.catch(function (error) {
						instructorSelect.textContent = '';
						addOption(instructorSelect, '', 'Instructor profiles unavailable');
						instructorSelect.disabled = true;
						showStatus('Could not load instructor profiles. Configure the authenticated admin instructor service first.', 'danger');
						console.error('Could not load instructor profiles:', error);
					});
			}

			function loadAssignments() {
				var instructorId = instructorSelect.value;
				assignedModuleIds = {};
				pendingModuleIds = {};
				document.getElementById('instructorDetails').textContent = '';
				if (!instructorId) {
					document.getElementById('moduleSummary').textContent = 'Choose an instructor to view current module assignments.';
					renderModules();
					return;
				}
				var instructor = instructors.find(function (item) { return String(item.instructor_id) === instructorId; });
				document.getElementById('instructorDetails').textContent = instructor
					? 'Employee code: ' + (instructor.employee_code || 'Not provided') +
						(instructor.department ? ' · ' + instructor.department : '')
					: '';
				document.getElementById('moduleSummary').textContent = 'Loading current module assignments…';
				renderModules();
				fetch('../api/admin/instructor-module-assignments.php?instructor_id=' + encodeURIComponent(instructorId), {
					credentials: 'same-origin',
					headers: { Accept: 'application/json' }
				})
					.then(function (response) {
						if (!response.ok) throw new Error('Assignment service returned HTTP ' + response.status + '.');
						return response.json();
					})
					.then(function (payload) {
						if (!payload || !Array.isArray(payload.assignments)) throw new Error('The assignment service returned an invalid response.');
						payload.assignments.forEach(function (assignment) {
							if (assignment.module_id !== undefined) {
								assignedModuleIds[String(assignment.module_id)] = assignment;
							}
						});
						document.getElementById('moduleSummary').textContent =
							Object.keys(assignedModuleIds).length + ' modules currently assigned';
						renderModules();
					})
					.catch(function (error) {
						showStatus('Could not load current module assignments for this instructor.', 'danger');
						document.getElementById('moduleSummary').textContent = 'Current assignment status is unavailable.';
						console.error('Could not load instructor module assignments:', error);
					});
			}

			function saveAssignments() {
				var instructorId = instructorSelect.value;
				var moduleIds = Object.keys(pendingModuleIds);
				if (!instructorId || !moduleIds.length) return;
				var button = document.getElementById('assignButton');
				button.disabled = true;
				button.textContent = 'Assigning…';
				fetch('../api/admin/instructor-module-assignments.php', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
					body: JSON.stringify({ instructor_id: instructorId, module_ids: moduleIds })
				})
					.then(function (response) {
						if (!response.ok) throw new Error('Assignment service returned HTTP ' + response.status + '.');
						return response.json();
					})
					.then(function (payload) {
						if (!payload || !Array.isArray(payload.assignments)) throw new Error('The assignment service returned an invalid response.');
						clearStatus();
						showStatus('Instructor assigned to ' + payload.assignments.length + ' module(s).', 'success');
						loadAssignments();
					})
					.catch(function (error) {
						showStatus('Could not save these assignments. Check the admin assignment service and try again.', 'danger');
						console.error('Could not save instructor module assignments:', error);
					})
					.finally(function () {
						button.textContent = 'Assign selected modules';
						updateSelectionCount();
					});
			}

			document.getElementById('selectVisible').addEventListener('click', function () {
				moduleList.querySelectorAll('input[type="checkbox"]:not(:disabled)').forEach(function (checkbox) {
					checkbox.checked = true;
					pendingModuleIds[checkbox.value] = true;
				});
				updateSelectionCount();
			});
			document.getElementById('clearSelection').addEventListener('click', function () {
				pendingModuleIds = {};
				renderModules();
			});
			instructorSelect.addEventListener('change', loadAssignments);
			courseFilter.addEventListener('change', function () {
				fillSemesterFilter();
				renderModules();
			});
			semesterFilter.addEventListener('change', renderModules);
			document.getElementById('assignButton').addEventListener('click', saveAssignments);

			try {
				loadCourseCatalog();
				fillCourseFilter();
				fillSemesterFilter();
			} catch (error) {
				showStatus(error.message, 'warning');
				console.error('Could not load course catalog:', error);
			}
			loadInstructors();

			function loadPartial(id, file, done) {
				fetch(file)
					.then(function (response) {
						if (!response.ok) throw new Error('HTTP error! status: ' + response.status);
						return response.text();
					})
					.then(function (html) {
						document.getElementById(id).innerHTML = html;
						if (done) done();
					})
					.catch(function (error) { console.error('Error loading ' + file + ':', error); });
			}

			loadPartial('header-placeholder', '../layouts/header.php');
			loadPartial('footer-placeholder', '../layouts/footer.php', function () {
				var footerYear = document.getElementById('footerYear');
				if (footerYear) footerYear.textContent = new Date().getFullYear();
			});
		}());
	
    },
    // Form/page: Student interaction report
    'instructor/studentinteraction.html': function () {
		(function () {
			var interactions = [];
			var rows = document.getElementById('interactionRows');
			var status = document.getElementById('interactionStatus');
			var activityNames = {
				material_view: 'Viewed material',
				material_download: 'Downloaded material',
				assignment_submit: 'Submitted assignment'
			};

			function showStatus(message, type) {
				status.textContent = message;
				status.className = 'alert alert-' + type;
			}

			function clearStatus() {
				status.textContent = '';
				status.className = 'alert d-none';
			}

			function formatDate(value) {
				if (!value) return '—';
				var date = new Date(value);
				return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
			}

			function actionCategory(actionType) {
				var action = String(actionType || '').toLowerCase().replace(/[\s-]+/g, '_');
				if (/view.*material|material.*view/.test(action)) return 'material_view';
				if (/download.*material|material.*download/.test(action)) return 'material_download';
				if (action.indexOf('assignment') !== -1 && /submit|submission/.test(action)) return 'assignment_submit';
				return '';
			}

			function submissionTiming(interaction) {
				if (interaction.source !== 'submission' || !interaction.submission_timestamp || !interaction.deadline) return 'n/a';
				var submitted = new Date(interaction.submission_timestamp).getTime();
				var deadline = new Date(interaction.deadline).getTime();
				if (Number.isNaN(submitted) || Number.isNaN(deadline)) return 'n/a';
				if (interaction.is_late === true || interaction.is_late === 1 || interaction.is_late === '1') return 'late';
				if (interaction.is_late === false || interaction.is_late === 0 || interaction.is_late === '0') {
					return submitted < deadline ? 'early' : 'on-time';
				}
				if (submitted < deadline) return 'early';
				if (submitted === deadline) return 'on-time';
				return 'late';
			}

			function appendCell(row, value) {
				var cell = document.createElement('td');
				cell.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
				row.appendChild(cell);
				return cell;
			}

			function validatePayload(payload) {
				if (!payload || !Array.isArray(payload.activity_logs) || !Array.isArray(payload.submissions)) {
					throw new Error('The interaction service returned an invalid response.');
				}
				var activityLogs = payload.activity_logs.map(function (item) {
					if (!item || item.log_id === undefined || item.user_id === undefined ||
							typeof item.action_type !== 'string' || typeof item.timestamp !== 'string' ||
							typeof item.full_name !== 'string' || typeof item.course_name !== 'string') {
						throw new Error('An activity record does not match ACTIVITY_LOGS and its related records.');
					}
					var category = actionCategory(item.action_type);
					if (category !== 'material_view' && category !== 'material_download') return null;
					return {
						source: 'activity_log',
						action_type: item.action_type,
						timestamp: item.timestamp,
						full_name: item.full_name,
						course_name: item.course_name,
						material_title: item.material_title,
						assignment_title: item.assignment_title,
						material_id: item.material_id,
						assignment_id: item.assignment_id
					};
				}).filter(Boolean);
				var submissions = payload.submissions.map(function (item) {
					if (!item || item.submission_id === undefined || item.assignment_id === undefined ||
							item.student_id === undefined || typeof item.submission_timestamp !== 'string' ||
							typeof item.full_name !== 'string' || typeof item.course_name !== 'string') {
						throw new Error('A submission record does not match SUBMISSIONS and its related records.');
					}
					return {
						source: 'submission',
						action_type: 'assignment_submit',
						timestamp: item.submission_timestamp,
						submission_timestamp: item.submission_timestamp,
						is_late: item.is_late,
						deadline: item.deadline,
						full_name: item.full_name,
						course_name: item.course_name,
						assignment_title: item.assignment_title,
						assignment_id: item.assignment_id
					};
				});
				return activityLogs.concat(submissions);
			}

			function getFilteredInteractions() {
				var query = document.getElementById('searchFilter').value.trim().toLowerCase();
				var type = document.getElementById('typeFilter').value;
				var timing = document.getElementById('timingFilter').value;
				return interactions.filter(function (item) {
					var studentAndCourse = (item.full_name + ' ' + item.course_name).toLowerCase();
					return (!query || studentAndCourse.indexOf(query) !== -1) &&
						(!type || actionCategory(item.action_type) === type) &&
						(!timing || submissionTiming(item) === timing);
				});
			}

			function render() {
				var filtered = getFilteredInteractions();
				rows.textContent = '';
				var views = filtered.filter(function (item) { return actionCategory(item.action_type) === 'material_view'; }).length;
				var downloads = filtered.filter(function (item) { return actionCategory(item.action_type) === 'material_download'; }).length;
				var submissionCount = filtered.filter(function (item) { return item.source === 'submission'; }).length;
				var early = filtered.filter(function (item) { return submissionTiming(item) === 'early'; }).length;
				var onTime = filtered.filter(function (item) { return submissionTiming(item) === 'on-time'; }).length;
				var late = filtered.filter(function (item) { return submissionTiming(item) === 'late'; }).length;
				document.getElementById('viewCount').textContent = String(views);
				document.getElementById('downloadCount').textContent = String(downloads);
				document.getElementById('submissionCount').textContent = String(submissionCount);
				document.getElementById('earlyCount').textContent = String(early);
				document.getElementById('onTimeCount').textContent = String(onTime);
				document.getElementById('lateCount').textContent = String(late);

				if (!filtered.length) {
					var emptyRow = document.createElement('tr');
					var emptyCell = document.createElement('td');
					emptyCell.colSpan = 8;
					emptyCell.className = 'empty-state';
					emptyCell.textContent = interactions.length
						? 'No interactions match these filters.'
						: 'No student interactions have been recorded.';
					emptyRow.appendChild(emptyCell);
					rows.appendChild(emptyRow);
					return;
				}

				filtered.forEach(function (item) {
					var row = document.createElement('tr');
					var category = actionCategory(item.action_type);
					appendCell(row, formatDate(item.timestamp));
					appendCell(row, item.full_name);
					appendCell(row, item.course_name);
					appendCell(row, activityNames[category] || item.action_type);
					appendCell(row, item.material_title || item.assignment_title);
					appendCell(row, category === 'assignment_submit' ? formatDate(item.submission_timestamp) : '—');
					appendCell(row, category === 'assignment_submit' ? formatDate(item.deadline) : '—');
					var timing = submissionTiming(item);
					var timingCell = appendCell(row, timing === 'n/a' ? '—' : timing === 'on-time' ? 'On time' : timing);
					  timingCell.className = 'timing timing-' + (timing === 'n/a' ? 'na' : timing);
					rows.appendChild(row);
				});
			}

			function loadInteractions() {
				fetch('../api/instructor/student-interactions.php', {
					credentials: 'same-origin',
					headers: { Accept: 'application/json' }
				})
					.then(function (response) {
						if (!response.ok) throw new Error('Interaction service returned HTTP ' + response.status + '.');
						return response.json();
					})
					.then(function (payload) {
						interactions = validatePayload(payload);
						render();
						clearStatus();
					})
					.catch(function (error) {
						rows.textContent = '';
						var errorRow = document.createElement('tr');
						var errorCell = document.createElement('td');
						errorCell.colSpan = 8;
						errorCell.className = 'empty-state';
						errorCell.textContent = 'Student interactions are unavailable right now.';
						errorRow.appendChild(errorCell);
						rows.appendChild(errorRow);
						showStatus('Could not load interactions. Configure the authenticated instructor interaction service to enable this report.', 'danger');
						console.error('Could not load student interactions:', error);
					});
			}

			['searchFilter', 'typeFilter', 'timingFilter'].forEach(function (id) {
				document.getElementById(id).addEventListener('input', render);
				document.getElementById(id).addEventListener('change', render);
			});
			document.getElementById('clearFilters').addEventListener('click', function () {
				document.getElementById('searchFilter').value = '';
				document.getElementById('typeFilter').value = '';
				document.getElementById('timingFilter').value = '';
				render();
			});

			function loadPartial(id, file, done) {
				fetch(file)
					.then(function (response) {
						if (!response.ok) throw new Error('HTTP error! status: ' + response.status);
						return response.text();
					})
					.then(function (html) {
						document.getElementById(id).innerHTML = html;
						if (done) done();
					})
					.catch(function (error) { console.error('Error loading ' + file + ':', error); });
			}

			loadInteractions();
			loadPartial('header-placeholder', '../layouts/header.php');
			loadPartial('footer-placeholder', '../layouts/footer.php', function () {
				var footerYear = document.getElementById('footerYear');
				if (footerYear) footerYear.textContent = new Date().getFullYear();
			});
		}());
	
    },
    // Form/page: Login form
    'login.php': function () {
    document.querySelectorAll('[data-clear]').forEach(function (button) {
      button.addEventListener('click', function () {
        var input = document.getElementById(button.getAttribute('data-clear'));
        input.value = '';
        input.focus();
      });
    });
  
    },
    // Form/page: Student enrollment form
    'student/studentenrolement.html': function () {
		(function () {
			var courseStorageKey = 'dtetCourseManagementSchemaV1';
			var students = [];
			var courses = [];
			var semesters = [];
			var modules = [];
			var enrolledModuleIds = {};
			var pendingModuleIds = {};
			var studentSelect = document.getElementById('studentSelect');
			var courseFilter = document.getElementById('courseFilter');
			var semesterFilter = document.getElementById('semesterFilter');
			var moduleList = document.getElementById('moduleList');
			var pageStatus = document.getElementById('pageStatus');

			function showStatus(message, type) {
				pageStatus.textContent = message;
				pageStatus.className = 'alert alert-' + type;
			}

			function clearStatus() {
				pageStatus.textContent = '';
				pageStatus.className = 'alert d-none';
			}

			function addOption(select, value, label) {
				var option = document.createElement('option');
				option.value = value;
				option.textContent = label;
				select.appendChild(option);
			}

			function loadCourseCatalog() {
				var stored = localStorage.getItem(courseStorageKey);
				if (!stored) throw new Error('No course catalog found. Add courses, semesters, and modules in Course Management first.');
				var data = JSON.parse(stored);
				if (!data || !Array.isArray(data.courses) || !Array.isArray(data.semesters) || !Array.isArray(data.modules)) {
					throw new Error('The saved course catalog is not in the expected format.');
				}
				courses = data.courses;
				semesters = data.semesters;
				modules = data.modules;
			}

			function fillCourseFilter() {
				courseFilter.textContent = '';
				addOption(courseFilter, '', 'All courses');
				courses.slice().sort(function (left, right) {
					return String(left.course_name).localeCompare(String(right.course_name));
				}).forEach(function (course) {
					addOption(courseFilter, String(course.course_id), course.course_code + ' - ' + course.course_name);
				});
				courseFilter.disabled = courses.length === 0;
			}

			function fillSemesterFilter() {
				var selectedCourse = courseFilter.value;
				var previousSemester = semesterFilter.value;
				var available = semesters.filter(function (semester) {
					return !selectedCourse || String(semester.course_id) === selectedCourse;
				});
				semesterFilter.textContent = '';
				addOption(semesterFilter, '', 'All semesters');
				available.slice().sort(function (left, right) {
					return Number(left.semester_number) - Number(right.semester_number);
				}).forEach(function (semester) {
					var course = courses.find(function (item) { return String(item.course_id) === String(semester.course_id); });
					var label = 'Semester ' + semester.semester_number + (semester.academic_year ? ' (' + semester.academic_year + ')' : '');
					if (!selectedCourse && course) label = course.course_code + ' - ' + label;
					addOption(semesterFilter, String(semester.semester_id), label);
				});
				semesterFilter.disabled = available.length === 0;
				if (available.some(function (item) { return String(item.semester_id) === previousSemester; })) {
					semesterFilter.value = previousSemester;
				}
			}

			function updateSelectionCount() {
				var count = Object.keys(pendingModuleIds).length;
				document.getElementById('selectionCount').textContent = count + (count === 1 ? ' module selected' : ' modules selected');
				document.getElementById('enrollButton').disabled = !studentSelect.value || count === 0;
				document.getElementById('clearSelection').disabled = count === 0;
			}

			function createEmptyState(message) {
				var state = document.createElement('div');
				state.className = 'empty-state';
				state.textContent = message;
				moduleList.appendChild(state);
			}

			function renderModules() {
				moduleList.textContent = '';
				var selectedCourse = courseFilter.value;
				var selectedSemester = semesterFilter.value;
				var visibleModules = modules.filter(function (module) {
					var semester = semesters.find(function (item) { return String(item.semester_id) === String(module.semester_id); });
					if (!semester) return false;
					return (!selectedCourse || String(semester.course_id) === selectedCourse) &&
						(!selectedSemester || String(semester.semester_id) === selectedSemester);
				});

				if (!studentSelect.value) {
					createEmptyState('Select a student to view enrollment status.');
					updateSelectionCount();
					return;
				}
				if (!visibleModules.length) {
					createEmptyState('No modules match the selected course and semester.');
					updateSelectionCount();
					return;
				}

				var grouped = {};
				visibleModules.forEach(function (module) {
					var semester = semesters.find(function (item) { return String(item.semester_id) === String(module.semester_id); });
					var course = courses.find(function (item) { return String(item.course_id) === String(semester.course_id); });
					if (!course) return;
					var key = String(course.course_id) + ':' + String(semester.semester_id);
					if (!grouped[key]) grouped[key] = { course: course, semester: semester, modules: [] };
					grouped[key].modules.push(module);
				});

				Object.keys(grouped).sort(function (left, right) {
					var leftGroup = grouped[left];
					var rightGroup = grouped[right];
					var courseOrder = String(leftGroup.course.course_name).localeCompare(String(rightGroup.course.course_name));
					return courseOrder || Number(leftGroup.semester.semester_number) - Number(rightGroup.semester.semester_number);
				}).forEach(function (key) {
					var group = grouped[key];
					var courseSection = document.createElement('section');
					courseSection.className = 'course-group';
					var courseHeading = document.createElement('h3');
					courseHeading.className = 'course-heading mb-0';
					courseHeading.textContent = group.course.course_code + ' - ' + group.course.course_name;
					courseSection.appendChild(courseHeading);

					var semesterGroup = document.createElement('div');
					semesterGroup.className = 'semester-group';
					var semesterHeading = document.createElement('h4');
					semesterHeading.className = 'semester-heading mb-2';
					semesterHeading.textContent = 'Semester ' + group.semester.semester_number +
						(group.semester.academic_year ? ' · ' + group.semester.academic_year : '');
					semesterGroup.appendChild(semesterHeading);

					group.modules.sort(function (left, right) {
						return String(left.module_name).localeCompare(String(right.module_name));
					}).forEach(function (module) {
						var moduleRow = document.createElement('div');
						moduleRow.className = 'module-row';
						var checkbox = document.createElement('input');
						checkbox.type = 'checkbox';
						checkbox.id = 'module-' + module.module_id;
						checkbox.value = String(module.module_id);
						var isEnrolled = Boolean(enrolledModuleIds[String(module.module_id)]);
						checkbox.checked = isEnrolled || Boolean(pendingModuleIds[String(module.module_id)]);
						checkbox.disabled = isEnrolled;
						checkbox.addEventListener('change', function () {
							if (checkbox.checked) pendingModuleIds[checkbox.value] = true;
							else delete pendingModuleIds[checkbox.value];
							updateSelectionCount();
						});

						var label = document.createElement('label');
						label.setAttribute('for', checkbox.id);
						label.appendChild(document.createTextNode((module.module_code ? module.module_code + ' - ' : '') + module.module_name));
						if (module.description) {
							var description = document.createElement('span');
							description.className = 'module-description';
							description.textContent = module.description;
							label.appendChild(description);
						}
						moduleRow.appendChild(checkbox);
						moduleRow.appendChild(label);
						if (isEnrolled) {
							var enrolledState = document.createElement('span');
							enrolledState.className = 'module-state';
							enrolledState.textContent = 'Enrolled' + (enrolledModuleIds[String(module.module_id)].enrollment_date
								? ' · ' + enrolledModuleIds[String(module.module_id)].enrollment_date
								: '');
							moduleRow.appendChild(enrolledState);
						}
						semesterGroup.appendChild(moduleRow);
					});
					courseSection.appendChild(semesterGroup);
					moduleList.appendChild(courseSection);
				});
				updateSelectionCount();
			}

			function loadStudents() {
				fetch('../api/admin/students.php', {
					credentials: 'same-origin',
					headers: { Accept: 'application/json' }
				})
					.then(function (response) {
						if (!response.ok) throw new Error('Student service returned HTTP ' + response.status + '.');
						return response.json();
					})
					.then(function (payload) {
						if (!payload || !Array.isArray(payload.students)) throw new Error('The student service returned an invalid response.');
						students = payload.students;
						studentSelect.textContent = '';
						addOption(studentSelect, '', 'Select a student profile');
						students.forEach(function (student) {
							if (student.student_id === undefined || !student.full_name) return;
							var label = student.registration_number
								? student.registration_number + ' - ' + student.full_name
								: student.full_name;
							addOption(studentSelect, String(student.student_id), label);
						});
						studentSelect.disabled = students.length === 0;
						if (!students.length) {
							showStatus('No student profiles were returned. Create student profiles before enrolling them.', 'warning');
						} else {
							clearStatus();
						}
					})
					.catch(function (error) {
						studentSelect.textContent = '';
						addOption(studentSelect, '', 'Student profiles unavailable');
						studentSelect.disabled = true;
						showStatus('Could not load student profiles. Configure the authenticated admin student service first.', 'danger');
						console.error('Could not load student profiles:', error);
					});
			}

			function loadEnrollments() {
				var studentId = studentSelect.value;
				enrolledModuleIds = {};
				pendingModuleIds = {};
				document.getElementById('studentDetails').textContent = '';
				if (!studentId) {
					document.getElementById('moduleSummary').textContent = 'Choose a student to view module enrollment status.';
					renderModules();
					return;
				}
				var student = students.find(function (item) { return String(item.student_id) === studentId; });
				document.getElementById('studentDetails').textContent = student
					? 'Registration: ' + (student.registration_number || 'Not provided')
					: '';
				document.getElementById('moduleSummary').textContent = 'Loading current module enrollments…';
				renderModules();
				fetch('../api/admin/student-module-enrollments.php?student_id=' + encodeURIComponent(studentId), {
					credentials: 'same-origin',
					headers: { Accept: 'application/json' }
				})
					.then(function (response) {
						if (!response.ok) throw new Error('Enrollment service returned HTTP ' + response.status + '.');
						return response.json();
					})
					.then(function (payload) {
						if (!payload || !Array.isArray(payload.enrollments)) throw new Error('The enrollment service returned an invalid response.');
						payload.enrollments.forEach(function (enrollment) {
							if (enrollment.module_id !== undefined) {
								enrolledModuleIds[String(enrollment.module_id)] = enrollment;
							}
						});
						document.getElementById('moduleSummary').textContent =
							Object.keys(enrolledModuleIds).length + ' modules currently enrolled';
						renderModules();
					})
					.catch(function (error) {
						showStatus('Could not load current enrollments for this student.', 'danger');
						document.getElementById('moduleSummary').textContent = 'Current enrollment status is unavailable.';
						console.error('Could not load student module enrollments:', error);
					});
			}

			function saveEnrollments() {
				var studentId = studentSelect.value;
				var moduleIds = Object.keys(pendingModuleIds);
				if (!studentId || !moduleIds.length) return;
				var button = document.getElementById('enrollButton');
				button.disabled = true;
				button.textContent = 'Enrolling…';
				fetch('../api/admin/student-module-enrollments.php', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
					body: JSON.stringify({ student_id: studentId, module_ids: moduleIds })
				})
					.then(function (response) {
						if (!response.ok) throw new Error('Enrollment service returned HTTP ' + response.status + '.');
						return response.json();
					})
					.then(function (payload) {
						if (!payload || !Array.isArray(payload.enrollments)) throw new Error('The enrollment service returned an invalid response.');
						clearStatus();
						showStatus('Student enrolled in ' + payload.enrollments.length + ' module(s).', 'success');
						loadEnrollments();
					})
					.catch(function (error) {
						showStatus('Could not save these enrollments. Check the admin enrollment service and try again.', 'danger');
						console.error('Could not save student module enrollments:', error);
					})
					.finally(function () {
						button.textContent = 'Enroll selected modules';
						updateSelectionCount();
					});
			}

			document.getElementById('selectVisible').addEventListener('click', function () {
				moduleList.querySelectorAll('input[type="checkbox"]:not(:disabled)').forEach(function (checkbox) {
					checkbox.checked = true;
					pendingModuleIds[checkbox.value] = true;
				});
				updateSelectionCount();
			});
			document.getElementById('clearSelection').addEventListener('click', function () {
				pendingModuleIds = {};
				renderModules();
			});
			studentSelect.addEventListener('change', loadEnrollments);
			courseFilter.addEventListener('change', function () {
				fillSemesterFilter();
				renderModules();
			});
			semesterFilter.addEventListener('change', renderModules);
			document.getElementById('enrollButton').addEventListener('click', saveEnrollments);

			try {
				loadCourseCatalog();
				fillCourseFilter();
				fillSemesterFilter();
			} catch (error) {
				showStatus(error.message, 'warning');
				console.error('Could not load course catalog:', error);
			}
			loadStudents();

			function loadPartial(id, file, done) {
				fetch(file)
					.then(function (response) {
						if (!response.ok) throw new Error('HTTP error! status: ' + response.status);
						return response.text();
					})
					.then(function (html) {
						document.getElementById(id).innerHTML = html;
						if (done) done();
					})
					.catch(function (error) { console.error('Error loading ' + file + ':', error); });
			}

			loadPartial('header-placeholder', '../layouts/header.php');
			loadPartial('footer-placeholder', '../layouts/footer.php', function () {
				var footerYear = document.getElementById('footerYear');
				if (footerYear) footerYear.textContent = new Date().getFullYear();
			});
		}());
	
    },
    // Form/page: Student activity tracking form
    'student/trackstudentactivity.html': function () {
    (function () {
      var activities = [];
      var status = document.getElementById('activityStatus');
      var rows = document.getElementById('activityRows');

      function showStatus(message, type) {
        status.textContent = message;
        status.className = 'alert alert-' + type;
      }

      function clearStatus() {
        status.textContent = '';
        status.className = 'alert d-none';
      }

      function appendCell(row, value) {
        var cell = document.createElement('td');
        cell.textContent = value === null || value === undefined || value === ''
          ? '—'
          : String(value);
        row.appendChild(cell);
      }

      function getFilteredActivities() {
        var action = document.getElementById('actionFilter').value;
        var fromDate = document.getElementById('fromDate').value;
        var toDate = document.getElementById('toDate').value;

        return activities.filter(function (activity) {
          var date = activity.timestamp.slice(0, 10);
          return (!action || activity.action_type === action) &&
            (!fromDate || date >= fromDate) &&
            (!toDate || date <= toDate);
        });
      }

      function render() {
        var filtered = getFilteredActivities();
        rows.textContent = '';

        document.getElementById('totalCount').textContent = String(filtered.length);
        document.getElementById('materialCount').textContent = String(
          filtered.filter(function (activity) { return activity.material_id !== null; }).length
        );
        document.getElementById('assignmentCount').textContent = String(
          filtered.filter(function (activity) { return activity.assignment_id !== null; }).length
        );

        if (!filtered.length) {
          var emptyRow = document.createElement('tr');
          var emptyCell = document.createElement('td');
          emptyCell.colSpan = 5;
          emptyCell.className = 'empty-state';
          emptyCell.textContent = activities.length
            ? 'No activity matches these filters.'
            : 'No activity has been recorded for your account.';
          emptyRow.appendChild(emptyCell);
          rows.appendChild(emptyRow);
          return;
        }

        filtered.forEach(function (activity) {
          var row = document.createElement('tr');
          var timestamp = new Date(activity.timestamp);
          appendCell(row, Number.isNaN(timestamp.getTime()) ? activity.timestamp : timestamp.toLocaleString());
          appendCell(row, activity.action_type);
          appendCell(row, activity.material_id);
          appendCell(row, activity.assignment_id);
          appendCell(row, activity.ip_address);
          rows.appendChild(row);
        });
      }

      function populateActionFilter() {
        var select = document.getElementById('actionFilter');
        var selected = select.value;
        var actions = [];
        activities.forEach(function (activity) {
          if (actions.indexOf(activity.action_type) === -1) actions.push(activity.action_type);
        });
        actions.sort();
        select.textContent = '';

        var allOption = document.createElement('option');
        allOption.value = '';
        allOption.textContent = 'All actions';
        select.appendChild(allOption);

        actions.forEach(function (action) {
          var option = document.createElement('option');
          option.value = action;
          option.textContent = action;
          select.appendChild(option);
        });

        if (actions.indexOf(selected) !== -1) select.value = selected;
      }

      function validateActivities(payload) {
        if (!payload || !Array.isArray(payload.activities)) {
          throw new Error('The activity service returned an invalid response.');
        }

        return payload.activities.map(function (activity) {
          if (!activity || activity.log_id === undefined ||
              typeof activity.action_type !== 'string' ||
              typeof activity.timestamp !== 'string') {
            throw new Error('The activity service returned a record that does not match ACTIVITY_LOGS.');
          }
          return {
            log_id: activity.log_id,
            material_id: activity.material_id === undefined ? null : activity.material_id,
            assignment_id: activity.assignment_id === undefined ? null : activity.assignment_id,
            action_type: activity.action_type,
            timestamp: activity.timestamp,
            ip_address: activity.ip_address === undefined ? null : activity.ip_address
          };
        });
      }

      function loadActivities() {
        fetch('../api/student/activity-logs.php', {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' }
        })
          .then(function (response) {
            if (!response.ok) {
              throw new Error('Activity service returned HTTP ' + response.status + '.');
            }
            return response.json();
          })
          .then(function (payload) {
            activities = validateActivities(payload);
            populateActionFilter();
            render();
            clearStatus();
          })
          .catch(function (error) {
            rows.textContent = '';
            var errorRow = document.createElement('tr');
            var errorCell = document.createElement('td');
            errorCell.colSpan = 5;
            errorCell.className = 'empty-state';
            errorCell.textContent = 'Your activity is unavailable right now.';
            errorRow.appendChild(errorCell);
            rows.appendChild(errorRow);
            document.getElementById('totalCount').textContent = '—';
            document.getElementById('materialCount').textContent = '—';
            document.getElementById('assignmentCount').textContent = '—';
            showStatus('Could not load your activity. The authenticated student activity service may not be configured.', 'danger');
            console.error('Could not load student activity:', error);
          });
      }

      document.getElementById('applyFilters').addEventListener('click', render);
      document.getElementById('clearFilters').addEventListener('click', function () {
        document.getElementById('actionFilter').value = '';
        document.getElementById('fromDate').value = '';
        document.getElementById('toDate').value = '';
        render();
      });

      function loadPartial(id, file, done) {
        fetch(file)
          .then(function (response) {
            if (!response.ok) throw new Error('HTTP error! status: ' + response.status);
            return response.text();
          })
          .then(function (html) {
            document.getElementById(id).innerHTML = html;
            if (done) done();
          })
          .catch(function (error) {
            console.error('Error loading ' + file + ':', error);
          });
      }

      loadActivities();
      loadPartial('header-placeholder', '../layouts/header.php');
      loadPartial('footer-placeholder', '../layouts/footer.php', function () {
        var footerYear = document.getElementById('footerYear');
        if (footerYear) footerYear.textContent = new Date().getFullYear();
      });
    }());
  
    },
  };

  if (Object.prototype.hasOwnProperty.call(initializers, page)) {
    initializers[page]();
  }
}());
