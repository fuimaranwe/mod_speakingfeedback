@mod @mod_speakingfeedback
Feature: Configure and access speaking practice
  In order to practise speaking with feedback
  As a teacher
  I need an activity with standard Moodle settings and protected student history

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | One | teacher1@example.com |
      | student1 | Student | One | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format | enablecompletion |
      | Practice course | PC | topics | 1 |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | PC | editingteacher |
      | student1 | PC | student |
    And the following "activities" exist:
      | activity | name | course | idnumber | instructions | preparationtime | recordingtime |
      | speakingfeedback | Speaking practice | PC | speaking1 | Describe your favourite place. | 30 | 120 |

  Scenario: Students see the task and a safe unconfigured state
    Given I am on the "Speaking practice" "speakingfeedback activity" page logged in as "student1"
    Then I should see "Describe your favourite place."
    And I should see "30 seconds preparation"
    And I should see "AI feedback is not configured"
    And I should see "Attempt history"
    And I should not see "Student attempts"

  Scenario: Teachers can review attempts without exposing an API key
    Given I am on the "Speaking practice" "speakingfeedback activity" page logged in as "teacher1"
    When I follow "Student attempts"
    Then I should see "No submitted attempts yet."
    And I should not see "Groq API key"
