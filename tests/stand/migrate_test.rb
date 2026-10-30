# Runs tools/migrate-identifiers.rb against in-memory stand-ins for the
# Chatwoot models it touches. ruby tests/stand/migrate_test.rb
require 'csv'
require 'stringio'
require 'tempfile'

Contact = Struct.new(:id, :identifier, :conversations) do
  def update!(attrs)
    if attrs[:identifier] && $contacts.any? { |c| c.id != id && c.identifier == attrs[:identifier] }
      raise "duplicate identifier #{attrs[:identifier]}"
    end
    attrs.each { |k, v| self[k] = v }
    self
  end
end

class Contacts
  def find_by(identifier:)
    $contacts.find { |c| c.identifier == identifier }
  end
end

Account = Struct.new(:contacts)

module Channel
  class WebWidget
    def self.find_by!(website_token:)
      raise 'unknown website token' unless website_token == 'tok'

      Struct.new(:account).new(Account.new(Contacts.new))
    end
  end
end

# Same contract as Chatwoot's: mergee's conversations go to base, mergee is
# destroyed, base keeps its own identifier.
class ContactMergeAction
  def initialize(account:, base_contact:, mergee_contact:)
    @base = base_contact
    @mergee = mergee_contact
  end

  def perform
    @base.conversations += @mergee.conversations
    $contacts.delete(@mergee)
    @base
  end
end

module ActiveRecord
  class Base
    def self.transaction
      yield
    end
  end
end

def run(rows, dry_run: false, reverse: false)
  file = Tempfile.new(%w[map .csv])
  file.write("old_identifier,new_identifier\n" + rows.map { |r| r.join(',') }.join("\n") + "\n")
  file.close
  ENV['CW_WEBSITE_TOKEN'] = 'tok'
  ENV['CW_MAP'] = file.path
  ENV['DRY_RUN'] = dry_run ? '1' : nil
  ENV['REVERSE'] = reverse ? '1' : nil
  out = StringIO.new
  $stdout = out
  load File.expand_path('../../tools/migrate-identifiers.rb', __dir__)
  out.string.strip
ensure
  $stdout = STDOUT
end

def check(name, ok)
  puts "#{ok ? 'PASS' : 'FAIL'} #{name}"
  $failed = true unless ok
end

a = 'a' * 64
b = 'b' * 64
c = 'c' * 64
d = 'd' * 64
rows = [[a, 'whmcs:u:1'], [b, 'whmcs:u:2'], [c, 'whmcs:u:3'], [d, 'whmcs:u:4'], ['nothex', 'whmcs:u:5']]

$contacts = [
  Contact.new(1, a, 3),              # renamed
  Contact.new(2, b, 5),              # merged with 20
  Contact.new(20, 'whmcs:u:2', 1),
  Contact.new(30, 'whmcs:u:3', 2),   # already migrated
]

dry = run(rows, dry_run: true)
check('dry run reports and changes nothing',
      dry.start_with?('DRY RUN') && $contacts.map(&:identifier) == [a, b, 'whmcs:u:2', 'whmcs:u:3'])

first = run(rows)
check("first run: #{first}", first == 'renamed=1 merged=1 missing=2 (already migrated: 1) invalid=1')
check('renamed contact keeps its id', $contacts.find { |x| x.id == 1 }.identifier == 'whmcs:u:1')
merged = $contacts.find { |x| x.id == 2 }
check('merged: old contact keeps id and history, takes the new identifier',
      merged.identifier == 'whmcs:u:2' && merged.conversations == 6 && $contacts.none? { |x| x.id == 20 })

second = run(rows)
check("second run changes nothing: #{second}", second == 'renamed=0 merged=0 missing=4 (already migrated: 3) invalid=1')

back = run(rows, reverse: true)
check("reverse run restores the old identifiers: #{back}",
      back == 'REVERSE renamed=3 merged=0 missing=1 (already migrated: 0) invalid=1' &&
      $contacts.find { |x| x.id == 1 }.identifier == a && $contacts.find { |x| x.id == 2 }.identifier == b)

exit($failed ? 1 : 0)
