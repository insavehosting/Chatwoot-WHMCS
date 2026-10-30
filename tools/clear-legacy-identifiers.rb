# Upgrading from 2.0.4: clears the 2.0.4 contact identifiers (HMAC-SHA256 of
# the WHMCS client id, 64 hex characters) on contacts of the WHMCS website
# inbox. With the identifier gone, Chatwoot matches the contact by email the
# next time its user logs in and gives it the whmcs:u:<id> identifier.
# Run with rails runner on the Chatwoot server, right after the module
# upload -- see docs/migrate-identifiers.md.
#
#   CW_WEBSITE_TOKEN  website token of the WHMCS widget inbox
#   APPLY=1           clear them; without it, only count

inbox = Channel::WebWidget.find_by!(website_token: ENV.fetch('CW_WEBSITE_TOKEN')).inbox
contacts = inbox.account.contacts
                .where(id: ContactInbox.where(inbox_id: inbox.id).select(:contact_id))
                .where("identifier ~ '^[0-9a-f]{64}$'")

if ENV['APPLY'] == '1'
  puts "cleared #{contacts.update_all(identifier: nil, updated_at: Time.current)}"
else
  puts "DRY RUN, nothing changed: #{contacts.count} contact(s) with a 2.0.4 identifier"
end
