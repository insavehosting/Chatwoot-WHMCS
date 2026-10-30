# Moves Chatwoot contacts from the 2.0.4 identifier (HMAC of the WHMCS client
# id) to whmcs:u:<user id>, using the CSV from export-identifier-map.php.
# Run with rails runner on the Chatwoot VM -- see docs/migrate-identifiers.md.
#
#   CW_WEBSITE_TOKEN  website token of the WHMCS widget inbox (picks the account)
#   CW_MAP            path to the CSV inside the container
#   DRY_RUN=1         report only, change nothing
#   REVERSE=1         the other way, whmcs:u:<id> -> old identifier, for a
#                     rollback to 2.0.4
#
# For each row: the contact with the old identifier gets the new one. If a
# contact with the new identifier exists already (the user chatted after the
# module upgrade), it is merged into the old one -- the old contact keeps its
# id and history -- and then renamed. Running it again changes nothing: the
# old identifiers are gone.

require 'csv'

token = ENV.fetch('CW_WEBSITE_TOKEN')
map = ENV.fetch('CW_MAP')
dry_run = ENV['DRY_RUN'] == '1'
reverse = ENV['REVERSE'] == '1'

account = Channel::WebWidget.find_by!(website_token: token).account
counts = Hash.new(0)

CSV.foreach(map, headers: true) do |row|
  hmac_identifier = row['old_identifier'].to_s.strip
  user_identifier = row['new_identifier'].to_s.strip
  unless hmac_identifier.match?(/\A\h{64}\z/) && user_identifier.match?(/\Awhmcs:u:\d+\z/)
    counts[:invalid] += 1
    next
  end
  old_identifier, new_identifier = reverse ? [user_identifier, hmac_identifier] : [hmac_identifier, user_identifier]

  old_contact = account.contacts.find_by(identifier: old_identifier)
  new_contact = account.contacts.find_by(identifier: new_identifier)

  if old_contact.nil?
    counts[:missing] += 1
    counts[:already] += 1 if new_contact
  elsif new_contact.nil?
    old_contact.update!(identifier: new_identifier) unless dry_run
    counts[:renamed] += 1
  else
    unless dry_run
      ActiveRecord::Base.transaction do
        contact = ContactMergeAction.new(
          account: account,
          base_contact: old_contact,
          mergee_contact: new_contact
        ).perform
        contact.update!(identifier: new_identifier)
      end
    end
    counts[:merged] += 1
  end
end

puts format(
  '%<mode>srenamed=%<renamed>d merged=%<merged>d missing=%<missing>d ' \
  '(already migrated: %<already>d) invalid=%<invalid>d',
  mode: (reverse ? 'REVERSE ' : '') + (dry_run ? 'DRY RUN, nothing changed: ' : ''),
  renamed: counts[:renamed], merged: counts[:merged], missing: counts[:missing],
  already: counts[:already], invalid: counts[:invalid]
)
