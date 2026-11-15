<?php
/** Staff pick the interview date and time directly; ApplicationReviewService validates them when saving. */
$interviewForm = $interviewForm ?? ['interview_date' => '', 'interview_time' => '', 'location' => $interview['location'] ?? '', 'sms_message_type' => 'automatic', 'custom_message' => ''];
?>
          <form method="post" class="d-flex flex-column gap-3 mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="schedule_interview">
            <label style="font-size:13px;font-weight:600;color:var(--ink)" data-i18n>
              <?= $ivStatus === 'BOOKED' ? 'Reschedule interview' : 'Schedule interview' ?>
            </label>
            <div class="d-flex flex-wrap gap-3">
              <label style="font-size:13px;font-weight:600;color:var(--ink);flex:1 1 180px"><span data-i18n>Interview date</span>
                <input type="date" id="interview-date" name="interview_date" class="form-control mt-1" required min="<?= e(date('Y-m-d')) ?>" value="<?= e($interviewForm['interview_date']) ?>">
              </label>
              <label style="font-size:13px;font-weight:600;color:var(--ink);flex:1 1 140px"><span data-i18n>Interview time</span>
                <input type="time" id="interview-time" name="interview_time" class="form-control mt-1" required value="<?= e($interviewForm['interview_time']) ?>">
              </label>
            </div>
            <div class="small text-muted" data-i18n>Choose an office day within office hours. Weekends, holidays and announced closures are not accepted.</div>
            <label class="user-label"><span data-i18n>Interview location</span><input type="text" name="location" aria-label="Interview location" maxlength="255" value="<?= e($interviewForm['location']) ?>" placeholder="Location (e.g. MSWDO Office, Municipal Building)" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px" required></label>
            <label style="font-size:13px;font-weight:600;color:var(--ink)">SMS message option
              <select name="sms_message_type" id="sms-message-type" class="form-select mt-1">
                <option value="automatic" <?= $interviewForm['sms_message_type'] !== 'custom' ? 'selected' : '' ?>>Automatic Message</option>
                <option value="custom" <?= $interviewForm['sms_message_type'] === 'custom' ? 'selected' : '' ?>>Custom Message</option>
              </select>
            </label>
            <div id="automatic-sms-preview" class="info-box small" style="max-width:none;margin:0" role="status">Select an interview date and time and enter the location to preview the automatic message.</div>
            <label id="custom-sms-wrap" class="d-none" style="font-size:13px;font-weight:600;color:var(--ink)">Review and edit the SMS message
              <textarea name="custom_message" id="custom-sms-message" rows="4" maxlength="500" class="form-control mt-1" placeholder="Write the message that will be sent"><?= e($interviewForm['custom_message']) ?></textarea>
              <span class="small text-muted">Up to 500 characters. This exact message will be sent instead of the automatic message.</span>
            </label>
            <button type="submit" class="btn-navy">Schedule Interview and Send SMS</button>
          </form>
          <script>
            (() => {
              const date = document.getElementById('interview-date');
              const time = document.getElementById('interview-time');
              const location = document.querySelector('input[name="location"]');
              const option = document.getElementById('sms-message-type');
              const preview = document.getElementById('automatic-sms-preview');
              const customWrap = document.getElementById('custom-sms-wrap');
              const custom = document.getElementById('custom-sms-message');
              const applicant = <?= json_encode($app['full_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
              const reference = <?= json_encode($app['reference_no'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
              const unchecked = <?= json_encode(array_values(array_column($uncheckedRequirements, 'label')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
              const textLength = text => Array.from(text).length;
              const preparation = maxLength => {
                if (!unchecked.length) return '';
                const labels = unchecked.map(label => label.replace(/\s+/gu, ' ').trim());
                for (let count = labels.length; count > 0; count--) {
                  const remaining = labels.length - count;
                  const text = 'After your interview, submit missing documents before ID release: ' + labels.slice(0, count).join('; ') + '.'
                    + (remaining ? ' Plus ' + remaining + ' more; view your full checklist online.' : '');
                  if (textLength(text) <= maxLength) return text;
                }
                return 'After your interview, submit ' + labels.length + ' missing documents before ID release. View the full checklist online.';
              };
              let customEdited = custom.value.trim() !== '';
              const update = () => {
                if (!date.value || !time.value || !location.value.trim()) {
                  preview.textContent = 'Select an interview date and time and enter the location to preview the automatic message.';
                  return;
                }
                const [year, month, day] = date.value.split('-').map(Number);
                const [hour, minute] = time.value.split(':').map(Number);
                const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                const clock = (hour % 12 || 12) + ':' + String(minute).padStart(2, '0') + (hour >= 12 ? ' PM' : ' AM');
                const when = months[month - 1] + ' ' + day + ', ' + year + ' at ' + clock;
                const base = 'Hello ' + applicant + ', interview for ' + reference + ': ' + when
                  + '. Location: ' + location.value.trim() + '. Please arrive on time.';
                const requirements = preparation(499 - textLength(base));
                const message = base + (requirements ? ' ' + requirements : '');
                preview.textContent = message;
                if (!customEdited) custom.value = message;
              };
              const toggle = () => {
                const isCustom = option.value === 'custom';
                customWrap.classList.toggle('d-none', !isCustom);
                custom.required = isCustom;
              };
              date.addEventListener('input', update);
              time.addEventListener('input', update);
              location.addEventListener('input', update);
              custom.addEventListener('input', () => { customEdited = true; });
              option.addEventListener('change', toggle);
              update(); toggle();
            })();
          </script>
