# Runs tools/clear-legacy-identifiers.rb against in-memory stand-ins for the
# Chatwoot models and the relation calls it makes.
# ruby tests/stand/clear_test.rb
require 'stringio'

# ActiveSupport's Time.current, as in Rails
def Time.current
  now
end

Contact = Struct.new(:id, :identifier, :inbox_ids)

class Relation
  def initialize(rows)
    @rows = rows
  end

  def where(condition)
    rows = @rows
    if condition.is_a?(Hash) && condition.key?(:id)
      ids = condition[:id]
      rows = rows.select { |c| ids.include?(c.id) }
    elsif condition == "identifier ~ '^[0-9a-f]{64}$'"
      rows = rows.select { |c| c.identifier.to_s.match?(/\A[0-9a-f]{64}\z/) }
    else
      raise "unexpected condition #{condition.inspect}"
    end
    Relation.new(rows)
  end

  def count
    @rows.size
  end

  def update_all(attrs)
    @rows.each { |c| c.identifier = attrs.fetch(:identifier) }
    @rows.size
  end
end

class ContactInboxIds
  def initialize(ids)
    @ids = ids
  end

  def select(_column)
    @ids
  end
end

class ContactInbox
  def self.where(inbox_id:)
    ContactInboxIds.new($contacts.select { |c| c.inbox_ids.include?(inbox_id) }.map(&:id))
  end
end

module Channel
  class WebWidget
    def self.find_by!(website_token:)
      raise 'unknown website token' unless website_token == 'tok'

      account = Struct.new(:contacts).new(Relation.new($contacts))
      Struct.new(:inbox).new(Struct.new(:id, :account).new(1, account))
    end
  end
end

def run(apply: false)
  ENV['CW_WEBSITE_TOKEN'] = 'tok'
  ENV['APPLY'] = apply ? '1' : nil
  out = StringIO.new
  $stdout = out
  load File.expand_path('../../tools/clear-legacy-identifiers.rb', __dir__)
  out.string.strip
ensure
  $stdout = STDOUT
end

def check(name, ok)
  puts "#{ok ? 'PASS' : 'FAIL'} #{name}"
  $failed = true unless ok
end

hex = ->(c) { c * 64 }
$contacts = [
  Contact.new(1, hex['a'], [1]),        # 2.0.4 contact of the website inbox: cleared
  Contact.new(2, 'whmcs:u:5', [1]),     # new identifier: kept
  Contact.new(3, hex['b'], [2]),        # same shape, another inbox: kept
  Contact.new(4, nil, [1]),             # visitor: kept
  Contact.new(5, hex['c'].upcase, [1]), # not lowercase hex: kept
]

dry = run
check("dry run counts and changes nothing: #{dry}",
      dry == 'DRY RUN, nothing changed: 1 contact(s) with a 2.0.4 identifier' && $contacts[0].identifier == hex['a'])
applied = run(apply: true)
check("apply clears only 2.0.4 identifiers of the website inbox: #{applied}",
      applied == 'cleared 1' && $contacts.map(&:identifier) == [nil, 'whmcs:u:5', hex['b'], nil, hex['c'].upcase])
again = run
check("a second run finds nothing: #{again}", again == 'DRY RUN, nothing changed: 0 contact(s) with a 2.0.4 identifier')

exit($failed ? 1 : 0)
