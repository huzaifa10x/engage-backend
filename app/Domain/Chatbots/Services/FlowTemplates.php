<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Services;

use App\Domain\Chatbots\Engine\FlowGraph;

/**
 * Ready-to-use flows. Pick a template, fill its short form, publish.
 *
 * Every template keeps the business's own content out of the blocks' wording: shared details
 * (name, address, hours, store link, delivery areas…) are business variables ({{business.*}},
 * saved for the whole workspace from the form), and lists (categories, products, services,
 * slots…) come from the form. Each template passes the publish checks as it is.
 */
final class FlowTemplates
{
    public const KEYS = ['appointment', 'bakery', 'real_estate', 'clinic', 'cleaning', 'car_rental', 'menu', 'lead', 'blank'];

    private const SLOTS = ['09:00 AM', '10:00 AM', '11:00 AM', '12:00 PM', '02:00 PM', '03:00 PM', '04:00 PM', '05:00 PM'];

    /** @var array<string, array<string, mixed>> */
    private array $nodes = [];

    /**
     * What the template gallery shows: name, industry, what the flow does, and the form.
     * Field types: text, textarea, time, number, list (one per line), products, prices.
     * scope "business" = saved as a business variable for the whole workspace; "flow" = builds this flow.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalog(): array
    {
        $business = [
            ['key' => 'name', 'label' => 'Business name', 'type' => 'text', 'scope' => 'business', 'required' => true, 'placeholder' => 'Sweet Crumbs Bakery'],
            ['key' => 'phone', 'label' => 'Phone / WhatsApp number', 'type' => 'text', 'scope' => 'business', 'placeholder' => '+971 4 123 4567'],
            ['key' => 'address', 'label' => 'Address', 'type' => 'text', 'scope' => 'business', 'placeholder' => 'Shop 4, Al Wasl Road, Dubai'],
            ['key' => 'hours', 'label' => 'Opening hours (as you would say them)', 'type' => 'text', 'scope' => 'business', 'default' => 'Sunday to Thursday, 9:00 to 18:00', 'placeholder' => 'Daily 8 AM – 10 PM'],
        ];
        $slots = ['key' => 'slots', 'label' => 'Time slots (one per line, up to 10)', 'type' => 'list', 'scope' => 'flow', 'default' => self::SLOTS, 'max' => 10];
        $reminder = ['key' => 'reminder_template', 'label' => 'Reminder template (optional)', 'type' => 'template', 'scope' => 'flow',
            'hint' => 'An approved template sent before the appointment, even after 24 hours. Its first body variable gets the date and time. Leave empty for no reminder.'];
        $lead = ['key' => 'reminder_hours', 'label' => 'Send the reminder this many hours before', 'type' => 'number', 'scope' => 'flow', 'default' => 24];
        $api = ['key' => 'booking_api_url', 'label' => 'Your booking system API (optional)', 'type' => 'text', 'scope' => 'flow', 'placeholder' => 'https://api.example.com/bookings',
            'hint' => 'If set, every booking is also POSTed there as JSON.'];

        return [
            [
                'key' => 'appointment', 'name' => 'Appointment booking', 'industry' => 'Salons, services, consultants', 'icon' => 'calendar', 'recommended' => true,
                'description' => 'Book, reschedule or talk to the team: service → date → time → contact, confirmation and a reminder.',
                'steps' => ['Welcome: Book · Reschedule · Talk to us', 'Service list', 'Date (future working day)', 'Time slot list', 'Name and phone', 'Tag “Appointment” and confirm', 'Reminder before the slot'],
                'fields' => [...$business, ['key' => 'services', 'label' => 'Services (one per line, up to 10)', 'type' => 'list', 'scope' => 'flow', 'max' => 10, 'default' => ['Consultation', 'Follow-up visit', 'Treatment']], $slots, $reminder, $lead, $api],
            ],
            [
                'key' => 'bakery', 'name' => 'Bakery — cake ordering', 'industry' => 'Bakery, sweets, food shops', 'icon' => 'cake',
                'description' => 'Browse cakes by category as product cards and order on your website, or order a custom cake from a photo.',
                'steps' => ['Welcome: Order a Cake · Custom Cake · Help', 'Categories list → product cards', 'Same-day delivery before the cut-off time', 'Order on website or on WhatsApp', 'Custom cake: photo, occasion, date, budget → baker', 'Help: hours, delivery, pricing, payment, pickup'],
                'fields' => [
                    ...$business,
                    ['key' => 'store_url', 'label' => 'Online store link', 'type' => 'text', 'scope' => 'business', 'required' => true, 'placeholder' => 'https://sweetcrumbs.ae'],
                    ['key' => 'cutoff_time', 'label' => 'Same-day delivery cut-off', 'type' => 'time', 'scope' => 'business', 'default' => '14:00'],
                    ['key' => 'delivery_areas', 'label' => 'Delivery areas', 'type' => 'text', 'scope' => 'business', 'default' => 'all of Dubai'],
                    ['key' => 'payment_methods', 'label' => 'Payment methods', 'type' => 'text', 'scope' => 'business', 'default' => 'card, Apple Pay and cash on delivery'],
                    ['key' => 'pickup_rule', 'label' => 'Pickup rule', 'type' => 'text', 'scope' => 'business', 'default' => 'Pickup is free from our shop — order at least 4 hours ahead.'],
                    ['key' => 'price_from', 'label' => 'Cakes start from', 'type' => 'text', 'scope' => 'business', 'default' => 'AED 120'],
                    ['key' => 'categories', 'label' => 'Categories (one per line, up to 9)', 'type' => 'list', 'scope' => 'flow', 'max' => 9, 'default' => ['Birthday', 'Vintage', 'Kids', 'Photo', 'Wedding', 'Cupcakes']],
                    ['key' => 'products', 'label' => 'Products', 'type' => 'products', 'scope' => 'flow', 'hint' => '5–8 per category: name, photo link, price and the product page. Pick the category for each.',
                        'default' => self::sampleProducts()],
                ],
            ],
            [
                'key' => 'real_estate', 'name' => 'Real estate — lead qualification', 'industry' => 'Brokers, developers', 'icon' => 'home',
                'description' => 'Buy or rent, property type, area, bedrooms, budget and timeline; qualified leads go to an agent.',
                'steps' => ['Welcome: Buy · Rent · Talk to agent', 'Property type list', 'Area list', 'Bedrooms', 'Budget (number)', 'Timeline', 'Name and phone', 'Tag “Lead – Buy/Rent”', 'Open → agent now; closed → call tomorrow'],
                'fields' => [
                    ...$business,
                    ['key' => 'property_types', 'label' => 'Property types', 'type' => 'list', 'scope' => 'flow', 'max' => 10, 'default' => ['Apartment', 'Villa', 'Townhouse', 'Office']],
                    ['key' => 'communities', 'label' => 'Areas / communities (up to 10)', 'type' => 'list', 'scope' => 'flow', 'max' => 10, 'default' => ['Dubai Marina', 'Downtown Dubai', 'JVC', 'Business Bay', 'Dubai Hills', 'Arabian Ranches']],
                ],
            ],
            [
                'key' => 'clinic', 'name' => 'Clinic — appointment booking', 'industry' => 'Clinics, dental, medical centres', 'icon' => 'stethoscope',
                'description' => 'Department, doctor, date and time slot, patient details; confirmation and a reminder before the visit.',
                'steps' => ['Welcome: Book Appointment · Our Services · Talk to us', 'Department list', 'Doctor list (or no preference)', 'Date (future working day)', 'Time slot', 'Patient name and phone / Emirates ID', 'Tag and confirm', 'Reminder before the visit', 'Closed → reception confirms in the morning'],
                'fields' => [
                    ...$business,
                    ['key' => 'departments', 'label' => 'Departments', 'type' => 'list', 'scope' => 'flow', 'max' => 10, 'default' => ['General Practice', 'Dental', 'Dermatology', 'Pediatrics']],
                    ['key' => 'doctors', 'label' => 'Doctors (one per line, up to 9)', 'type' => 'list', 'scope' => 'flow', 'max' => 9, 'default' => ['Dr. Sara Ahmed', 'Dr. Omar Khan', 'Dr. Lina Haddad']],
                    $slots, $reminder, $lead, $api,
                ],
            ],
            [
                'key' => 'cleaning', 'name' => 'Home services — cleaning', 'industry' => 'Cleaning, maintenance, home services', 'icon' => 'sparkles',
                'description' => 'Service, size, frequency, photos, date, location and contact, with an indicative price.',
                'steps' => ['Welcome: Book a Cleaning · Get a Quote · Talk to us', 'Service list', 'Property size', 'Frequency', 'Photo of the space (deep clean)', 'Date and time window', 'Location', 'Name and phone', 'Indicative price (size × service)', 'Tag “Cleaning Lead” → operations'],
                'fields' => [
                    ...$business,
                    ['key' => 'service_areas', 'label' => 'Service areas', 'type' => 'text', 'scope' => 'business', 'default' => 'Dubai and Sharjah'],
                    ['key' => 'size_prices', 'label' => 'Price by size (AED, regular clean)', 'type' => 'prices', 'scope' => 'flow',
                        'default' => ['Studio' => 150, '1BR' => 200, '2BR' => 260, '3BR' => 330, 'Villa' => 550]],
                    ['key' => 'service_factors', 'label' => 'Price factor by service (× size price)', 'type' => 'prices', 'scope' => 'flow',
                        'default' => ['Regular' => 1, 'Deep' => 1.8, 'Move-in/out' => 2, 'Sofa' => 0.6, 'Carpet' => 0.5]],
                ],
            ],
            [
                'key' => 'car_rental', 'name' => 'Car rental — booking', 'industry' => 'Car rental, leasing', 'icon' => 'car',
                'description' => 'Category, model cards, dates (rental days calculated), pick-up location, eligibility and a deposit link.',
                'steps' => ['Welcome: Rent a Car · Our Fleet · Talk to us', 'Category list → model cards', 'Pick-up and return dates → days', 'Pick-up location', 'Age and licence check', 'Name and phone', 'Tag “Rental Lead”', 'Deposit link', 'Team confirms availability'],
                'fields' => [
                    ...$business,
                    ['key' => 'booking_url', 'label' => 'Booking / deposit page', 'type' => 'text', 'scope' => 'business', 'required' => true, 'placeholder' => 'https://rent.example.ae/reserve'],
                    ['key' => 'min_age', 'label' => 'Minimum driver age', 'type' => 'number', 'scope' => 'business', 'default' => 25],
                    ['key' => 'deposit', 'label' => 'Deposit', 'type' => 'text', 'scope' => 'business', 'default' => 'AED 1,000 (refundable)'],
                    ['key' => 'categories', 'label' => 'Categories', 'type' => 'list', 'scope' => 'flow', 'max' => 9, 'default' => ['Economy', 'Sedan', 'SUV', 'Luxury', 'Sports']],
                    ['key' => 'locations', 'label' => 'Pick-up locations', 'type' => 'list', 'scope' => 'flow', 'max' => 10, 'default' => ['Dubai Airport T1', 'Dubai Airport T3', 'Downtown branch', 'Dubai Marina branch']],
                    ['key' => 'products', 'label' => 'Cars', 'type' => 'products', 'scope' => 'flow', 'hint' => 'Model, photo link, “From X AED/day” and its booking page. Pick the category for each.',
                        'default' => self::sampleCars()],
                ],
            ],
            [
                'key' => 'menu', 'name' => 'Welcome menu', 'industry' => 'Any business', 'icon' => 'menu',
                'description' => 'A greeting with three buttons: your services, opening hours, or talk to a person.',
                'steps' => ['Welcome buttons', 'Services', 'Opening hours', 'Talk to a person'],
                'fields' => $business,
            ],
            [
                'key' => 'lead', 'name' => 'Lead capture', 'industry' => 'Any business', 'icon' => 'user',
                'description' => 'Asks the name and email, tags the contact as a lead and hands over to the team.',
                'steps' => ['Ask the name', 'Ask the email', 'Tag “Lead”', 'Hand over'],
                'fields' => [$business[0]],
            ],
            [
                'key' => 'blank', 'name' => 'Start from scratch', 'industry' => 'Any business', 'icon' => 'plus',
                'description' => 'Only a Start block and a handover to the team — build the rest yourself.',
                'steps' => ['Start', 'Talk to a person'],
                'fields' => [],
            ],
        ];
    }

    /** @return ?array<string, mixed> */
    public static function find(string $key): ?array
    {
        foreach (self::catalog() as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }

        return null;
    }

    /**
     * The form's business variables (saved for the whole workspace).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public static function business(string $key, array $config): array
    {
        $out = [];
        foreach ((array) (self::find($key)['fields'] ?? []) as $field) {
            if ($field['scope'] !== 'business') {
                continue;
            }
            $value = $config[$field['key']] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $out[$field['key']] = mb_substr(trim((string) $value), 0, 1000);
            } elseif (isset($field['default']) && is_scalar($field['default'])) {
                $out[$field['key']] = (string) $field['default'];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $config  the form's answers (missing = the field's default)
     * @return array{nodes: list<array<string, mixed>>}
     */
    public static function graph(string $key, array $config = []): array
    {
        $t = new self;
        $defaults = [];
        foreach ((array) (self::find($key)['fields'] ?? []) as $field) {
            $defaults[$field['key']] = $field['default'] ?? null;
        }
        $c = array_filter($config, fn ($v) => $v !== null && $v !== '' && $v !== []) + $defaults;

        match ($key) {
            'appointment' => $t->appointment($c),
            'bakery' => $t->bakery($c),
            'real_estate' => $t->realEstate($c),
            'clinic' => $t->clinic($c),
            'cleaning' => $t->cleaning($c),
            'car_rental' => $t->carRental($c),
            'lead' => $t->lead(),
            'blank' => $t->blank(),
            default => $t->menu(),
        };

        return ['nodes' => $t->layout()];
    }

    // ── Templates ────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $c */
    private function appointment(array $c): void
    {
        $this->start(['hi', 'hello', 'book', 'appointment', 'مرحبا'], 'welcome');
        $this->buttons('welcome', 'Welcome', 'Hi {{contact.first_name}} 👋 Welcome to {{business.name}}. How can we help you today?',
            ['book' => 'Book', 'reschedule' => 'Reschedule', 'talk' => 'Talk to us'], ['book' => 'service', 'reschedule' => 'has_booking', 'talk' => 'handoff']);
        $this->list('service', 'Choose a service', 'Which service would you like to book?', 'Services', self::lines($c['services'], 10), 'date');
        $this->question('date', 'Date', 'Which day suits you? Please type a date, e.g. 25/10/2026 📅', 'var:date', 'workday',
            'Please send a future date on a day we are open, e.g. 25/10/2026.', 'time');
        $this->list('time', 'Time slot', 'What time works best on {{var.date}}?', 'Times', self::lines($c['slots'], 10), 'name');
        $this->question('name', 'Your name', 'Almost done! What is your full name?', 'name', 'text', 'Please type your name.', 'phone');
        $this->question('phone', 'Phone number', 'And the best phone number to reach you?', 'var:phone', 'phone', 'Please send a valid phone number, e.g. +971 50 123 4567.', 'tag');
        $this->tag('tag', 'Tag “Appointment”', ['Appointment'], 'save');
        $this->set('save', 'Save the booking', 'var:booking', '{{var.service}} · {{var.date}} {{var.time}}', $this->api($c, 'confirm'));
        $this->message('confirm', 'Confirmation', "Booked ✅ {{var.service}} · {{var.date}} {{var.time}}\n📍 {{business.address}}\n\nSee you soon! Reply *menu* any time to start again.", $this->reminder($c, 'var.date', 'var.time', 'end'));
        $this->end('end', 'Done', null);
        $this->condition('has_booking', 'Has a booking?', [['field' => 'tag', 'op' => 'has_tag', 'value' => 'Appointment']], 'resched_yes', 'resched_no');
        $this->message('resched_yes', 'Pick a new time', 'No problem — let’s find a new time for you. 🗓️', 'resched_tag');
        $this->tag('resched_tag', 'Tag “Reschedule”', ['Reschedule request'], 'date');
        $this->message('resched_no', 'No booking found', 'I could not find a booking for this number, so let’s make a new one.', 'service');
        $this->handoff('handoff', 'Our team will reply shortly 🙂', 'Thanks for your message! We are closed right now ({{business.hours}}) and will reply as soon as we open.');
    }

    /** @param array<string, mixed> $c */
    private function bakery(array $c): void
    {
        $categories = self::lines($c['categories'], 9);
        $products = self::products($c['products']);

        $this->start(['hi', 'hello', 'menu', 'cake', 'order', 'مرحبا'], 'welcome');
        $this->buttons('welcome', 'Welcome', 'Hi 🎂 Welcome to {{business.name}} — fresh cakes, same-day delivery.',
            ['order' => 'Order a Cake', 'custom' => 'Custom Cake', 'help' => 'Help'], ['order' => 'categories', 'custom' => 'photo', 'help' => 'help', 'fallback' => 'help'],
            ['no_match' => 'branch', 'retries' => 1]);

        $rows = [];
        $to = [];
        foreach ($categories as $i => $category) {
            $id = 'cat'.($i + 1);
            $rows[] = ['id' => $id, 'title' => mb_substr($category, 0, 24)];
            $items = array_values(array_filter($products, fn ($p) => mb_strtolower($p['category']) === mb_strtolower($category)));
            if ($items === []) {
                $to[$id] = 'all';
            } else {
                $to[$id] = "products_{$id}";
                $this->add("products_{$id}", 'product', $category, [
                    'mode' => 'cards', 'text' => "Our {$category} cakes 🎂", 'order_label' => 'View & order',
                    'items' => array_map(fn ($p) => array_diff_key($p, ['category' => true]), array_slice($items, 0, 10)),
                    'all_label' => 'See all →', 'all_url' => '{{business.store_url}}',
                ], ['next' => 'delivery']);
            }
        }
        $rows[] = ['id' => 'all_cakes', 'title' => 'All Cakes'];
        $to['all_cakes'] = 'all';
        $this->add('categories', 'list', 'Cake categories', [
            'text' => 'What are you celebrating? Pick a category 👇', 'button_label' => 'Categories', 'sections' => [['title' => 'Categories', 'rows' => $rows]],
        ], $to);
        $this->add('all', 'url', 'All cakes', ['text' => 'Browse all our cakes on our website 🍰', 'cta' => 'See all cakes', 'url' => '{{business.store_url}}'], ['next' => 'delivery']);
        $this->add('delivery', 'hours', 'Before the cut-off?', ['mode' => 'custom', 'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'from' => '00:00', 'to' => self::clock($c['cutoff_time'] ?? null, '14:00')],
            ['open' => 'same_day', 'closed' => 'next_day']);
        $this->message('same_day', 'Same-day delivery', 'Same-day delivery 🚚 ({{business.delivery_areas}}) for orders placed before {{business.cutoff_time}}.', 'order');
        $this->message('next_day', 'Next-day delivery', 'Orders placed now are delivered tomorrow 🚚 ({{business.delivery_areas}}).', 'order');
        $this->buttons('order', 'How to order', 'How would you like to order?', ['web' => 'Order on Website', 'wa' => 'Order on WhatsApp'], ['web' => 'website', 'wa' => 'order_handoff']);
        $this->add('website', 'url', 'Order on the website', ['text' => 'Tap below to order on our website. Payment is completed on the website 🔒', 'cta' => 'Order now', 'url' => '{{business.store_url}}'], ['next' => 'thanks']);
        $this->end('thanks', 'Thank you', 'Thank you for choosing {{business.name}}! 🎂 Reply *menu* any time.');
        $this->handoff('order_handoff', 'Lovely! 🎂 A team member will take your order right here.', 'Thanks! We are closed right now and will take your order as soon as we open.');

        $this->add('photo', 'ask_media', 'Reference photo', ['text' => 'Share a reference photo 🎨 (optional)', 'accept' => 'image', 'optional' => true, 'skip_label' => 'Skip', 'save_to' => 'var:reference_photo'], ['next' => 'occasion']);
        $this->question('occasion', 'Occasion', 'What is the occasion? (birthday, wedding, anniversary…)', 'var:occasion', 'text', null, 'needed');
        $this->question('needed', 'Date needed', 'When do you need it? Please send the date (at least 1 day ahead), e.g. 25/10/2026', 'var:needed_date', 'future_date',
            'Custom cakes need at least one day. Please send a date from tomorrow on, e.g. 25/10/2026.', 'budget');
        $this->question('budget', 'Budget', 'What is your budget in AED?', 'var:budget', 'number', 'Please send the amount as a number, e.g. 350.', 'custom_tag');
        $this->tag('custom_tag', 'Tag “Custom Cake Lead”', ['Custom Cake Lead'], 'custom_handoff');
        $this->handoff('custom_handoff', 'A baker will confirm design & price shortly 👩‍🍳', 'Thanks! Our bakers will confirm design & price as soon as we open 👩‍🍳');

        $this->add('help', 'list', 'Help menu', ['text' => 'How can we help? 👇', 'button_label' => 'Help topics', 'sections' => [['title' => 'Help', 'rows' => [
            ['id' => 'hours', 'title' => 'Hours'], ['id' => 'areas', 'title' => 'Delivery areas'], ['id' => 'pricing', 'title' => 'Pricing'],
            ['id' => 'payment', 'title' => 'Payment'], ['id' => 'pickup', 'title' => 'Pickup'], ['id' => 'talk', 'title' => 'Talk to us'],
        ]]]], ['hours' => 'h_hours', 'areas' => 'h_areas', 'pricing' => 'h_pricing', 'payment' => 'h_payment', 'pickup' => 'h_pickup', 'talk' => 'handoff']);
        $this->message('h_hours', 'Hours', "We are open {{business.hours}} 🕘\n📍 {{business.address}}", 'more');
        $this->message('h_areas', 'Delivery areas', 'We deliver to {{business.delivery_areas}}. Order before {{business.cutoff_time}} for same-day delivery 🚚', 'more');
        $this->message('h_pricing', 'Pricing', 'Our cakes start from {{business.price_from}}. Custom cakes are priced by design and size 🎂', 'more');
        $this->message('h_payment', 'Payment', 'We accept {{business.payment_methods}}. 💳', 'more');
        $this->message('h_pickup', 'Pickup', '{{business.pickup_rule}}', 'more');
        $this->buttons('more', 'Anything else?', 'Anything else I can help with?', ['menu' => 'Main menu', 'talk' => 'Talk to us', 'done' => 'No, thanks'], ['menu' => 'back_menu', 'talk' => 'handoff', 'done' => 'thanks']);
        $this->add('back_menu', 'goto', 'Back to the menu', ['target' => 'welcome'], []);
        $this->handoff('handoff', 'Connecting you with our team 👩‍🍳 Someone will reply shortly.', 'Thanks! We are closed right now ({{business.hours}}) and will reply as soon as we open.');
    }

    /** @param array<string, mixed> $c */
    private function realEstate(array $c): void
    {
        $this->start(['hi', 'hello', 'buy', 'rent', 'property', 'مرحبا'], 'welcome');
        $this->buttons('welcome', 'Welcome', 'Hi {{contact.first_name}} 👋 Welcome to {{business.name}}. Are you looking to buy or rent?',
            ['buy' => 'Buy', 'rent' => 'Rent', 'agent' => 'Talk to agent'], ['buy' => 'intent_buy', 'rent' => 'intent_rent', 'agent' => 'handoff']);
        $this->set('intent_buy', 'Purpose = Buy', 'var:purpose', 'Buy', 'type');
        $this->set('intent_rent', 'Purpose = Rent', 'var:purpose', 'Rent', 'type');
        $this->list('type', 'Property type', 'What type of property?', 'Property types', self::lines($c['property_types'], 10), 'area');
        $this->list('area', 'Area', 'Which area are you interested in? 📍', 'Areas', self::lines($c['communities'], 10), 'bedrooms');
        $this->list('bedrooms', 'Bedrooms', 'How many bedrooms?', 'Bedrooms', ['Studio', '1', '2', '3+'], 'budget');
        $this->question('budget', 'Budget', 'What is your budget in AED? (a number, e.g. 1500000)', 'var:budget', 'number', 'Please send the budget as a number, e.g. 1500000.', 'timeline');
        $this->buttons('timeline', 'Timeline', 'When are you planning to move?', ['asap' => 'ASAP', 'soon' => '1–3 months', 'browsing' => 'Just browsing'],
            ['asap' => 'name', 'soon' => 'name', 'browsing' => 'name']);
        $this->question('name', 'Your name', 'Great! What is your full name?', 'name', 'text', 'Please type your name.', 'phone');
        $this->question('phone', 'Phone number', 'And the best number for the agent to call you?', 'var:phone', 'phone', 'Please send a valid phone number, e.g. +971 50 123 4567.', 'qualify');
        $this->tag('qualify', 'Tag “Lead – Buy/Rent”', ['Lead – {{var.purpose}}'], 'criteria');
        $this->set('criteria', 'Store the criteria', 'var:criteria', '{{var.purpose}} · {{var.type}} · {{var.area}} · {{var.bedrooms}} BR · AED {{var.budget}} · {{var.timeline}}', 'route');
        $this->add('route', 'hours', 'Agents available?', ['mode' => 'business'], ['open' => 'agent_now', 'closed' => 'callback_note']);
        $this->add('agent_now', 'handoff', 'Assign an agent', ['text' => 'Thank you {{contact.first_name}}! An agent will share matching listings 🏠 right here.', 'assign' => 'auto', 'note' => true], []);
        $this->add('callback_note', 'note', 'Call back tomorrow', ['text' => 'Call-back requested for tomorrow: {{var.criteria}} — {{var.phone}}'], ['next' => 'callback']);
        $this->add('callback', 'tag', 'Assign an agent', ['add' => ['Call back'], 'assign' => 'auto'], ['next' => 'end']);
        $this->end('end', 'Done', 'Thanks {{contact.first_name}}! Our office is closed now — an agent will call you tomorrow and share matching listings 🏠');
        $this->handoff('handoff', 'Connecting you with an agent 🏠 Someone will reply shortly.', 'Thanks! Our agents are away right now and will reply first thing tomorrow.');
    }

    /** @param array<string, mixed> $c */
    private function clinic(array $c): void
    {
        $this->start(['hi', 'hello', 'book', 'appointment', 'doctor', 'مرحبا'], 'welcome');
        $this->buttons('welcome', 'Welcome', 'Hi {{contact.first_name}} 👋 Welcome to {{business.name}}. How can we help?',
            ['book' => 'Book Appointment', 'services' => 'Our Services', 'talk' => 'Talk to us'], ['book' => 'department', 'services' => 'services', 'talk' => 'handoff']);
        $departments = self::lines($c['departments'], 10);
        $this->message('services', 'Our services', "Our departments:\n".implode("\n", array_map(fn ($d) => "• {$d}", $departments))."\n\nOpening hours: {{business.hours}}\n📍 {{business.address}}", 'services_next');
        $this->buttons('services_next', 'Book now?', 'Would you like to book an appointment?', ['book' => 'Book Appointment', 'talk' => 'Talk to us'], ['book' => 'department', 'talk' => 'handoff']);
        $this->list('department', 'Department', 'Which department would you like to visit?', 'Departments', $departments, 'doctor');
        $this->list('doctor', 'Doctor', 'Do you have a preferred doctor?', 'Doctors', [...self::lines($c['doctors'], 9), 'No preference'], 'date');
        $this->question('date', 'Date', 'Which day would you like to come? Please type a date, e.g. 25/10/2026 📅', 'var:date', 'workday',
            'Please send a future date on a day the clinic is open, e.g. 25/10/2026.', 'time');
        $this->list('time', 'Time slot', 'Which time on {{var.date}}?', 'Times', self::lines($c['slots'], 10), 'patient');
        $this->question('patient', 'Patient name', 'What is the patient’s full name?', 'var:patient', 'text', 'Please type the full name.', 'id');
        $this->add('id', 'question', 'Phone or Emirates ID', [
            'text' => 'Please send the patient’s phone number or Emirates ID.', 'save_to' => 'var:patient_id', 'validate' => 'regex',
            'pattern' => '^(\+?[0-9][0-9 -]{6,18}|784-?[0-9]{4}-?[0-9]{7}-?[0-9])$',
            'error_text' => 'Please send a phone number (e.g. +971 50 123 4567) or an Emirates ID (784-XXXX-XXXXXXX-X).',
        ], ['next' => 'book']);
        $this->set('book', 'Save the booking', 'var:booking', '{{var.department}} · {{var.doctor}} · {{var.date}} {{var.time}}', 'tag');
        $this->tag('tag', 'Tag “Appointment”', ['Appointment'], $this->api($c, 'open'));
        $this->add('open', 'hours', 'Reception open?', ['mode' => 'business'], ['open' => 'confirm', 'closed' => 'confirm_closed']);
        $this->message('confirm', 'Confirmation', 'Booked ✅ {{var.department}} on {{var.date}} at {{var.time}}. See you soon!', $this->reminder($c, 'var.date', 'var.time', 'end'));
        $this->message('confirm_closed', 'Off-hours', 'Booked ✅ {{var.department}} on {{var.date}} at {{var.time}}. Reception will confirm in the morning.', $this->nodes['confirm']['outputs']['next']);
        $this->end('end', 'Done', null);
        $this->handoff('handoff', 'Connecting you with our reception 👩‍⚕️ Someone will reply shortly.', 'Thanks! Reception is closed right now and will reply in the morning.');
    }

    /** @param array<string, mixed> $c */
    private function cleaning(array $c): void
    {
        $sizes = self::prices($c['size_prices']);
        $factors = self::prices($c['service_factors']);
        $this->start(['hi', 'hello', 'cleaning', 'quote', 'book', 'مرحبا'], 'welcome');
        $this->buttons('welcome', 'Welcome', 'Hi {{contact.first_name}} 👋 Welcome to {{business.name}} 🧽 How can we help?',
            ['book' => 'Book a Cleaning', 'quote' => 'Get a Quote', 'talk' => 'Talk to us'], ['book' => 'service', 'quote' => 'service', 'talk' => 'handoff']);
        $this->list('service', 'Service', 'Which service do you need?', 'Services', array_slice(array_keys($factors), 0, 10), 'size');
        $this->list('size', 'Property size', 'What is the size of the property?', 'Sizes', array_slice(array_keys($sizes), 0, 10), 'frequency');
        $this->buttons('frequency', 'Frequency', 'How often?', ['once' => 'One-time', 'weekly' => 'Weekly', 'biweekly' => 'Biweekly'], ['once' => 'is_deep', 'weekly' => 'is_deep', 'biweekly' => 'is_deep']);
        $this->condition('is_deep', 'Deep clean?', [['field' => 'var:service', 'op' => 'contains', 'value' => 'deep']], 'photos', 'date');
        $this->add('photos', 'ask_media', 'Photo of the space', ['text' => 'Send a photo of the space so we can plan the deep clean 📷 (optional)', 'accept' => 'image', 'optional' => true, 'save_to' => 'var:photo'], ['next' => 'date']);
        $this->question('date', 'Date', 'Which date would you like? e.g. 25/10/2026 📅', 'var:date', 'future_date', 'Please send a future date, e.g. 25/10/2026.', 'window');
        $this->list('window', 'Time window', 'Preferred time window?', 'Time windows', ['Morning 8–12', 'Afternoon 12–4', 'Evening 4–8'], 'location');
        $this->add('location', 'location', 'Location', ['text' => 'Please share your location pin 📍 or type your area ({{business.service_areas}}).', 'save_to' => 'location'], ['next' => 'name']);
        $this->question('name', 'Your name', 'What is your name?', 'name', 'text', 'Please type your name.', 'phone');
        $this->question('phone', 'Phone number', 'And the best phone number to reach you?', 'var:phone', 'phone', 'Please send a valid phone number, e.g. +971 50 123 4567.', 'price');
        $pick = fn (string $var, array $table) => "pick({{var.{$var}}}, ".implode(', ', array_map(fn ($k, $v) => "'".str_replace("'", '', $k)."', {$v}", array_keys($table), $table)).', 0)';
        $this->add('price', 'calculate', 'Indicative price', ['expression' => $pick('size', $sizes).' * '.$pick('service', $factors), 'target' => 'var:price', 'decimals' => 0], ['next' => 'has_price']);
        $this->condition('has_price', 'Price known?', [['field' => 'var:price', 'op' => 'gt', 'value' => '0']], 'quote', 'lead');
        $this->message('quote', 'Your quote', 'Your indicative price: *AED {{var.price}}* for a {{var.frequency}} {{var.service}} clean of a {{var.size}}. Our team will confirm the final price.', 'lead');
        $this->tag('lead', 'Tag “Cleaning Lead”', ['Cleaning Lead'], 'ops');
        $this->handoff('ops', 'Thank you! Our operations team will confirm your booking shortly 🧽', 'Thank you! Our operations team will confirm your booking as soon as we open 🧽');
        $this->handoff('handoff', 'Connecting you with our team 🧽 Someone will reply shortly.', 'Thanks! We are closed right now ({{business.hours}}) and will reply as soon as we open.');
    }

    /** @param array<string, mixed> $c */
    private function carRental(array $c): void
    {
        $categories = self::lines($c['categories'], 9);
        $cars = self::products($c['products']);
        $this->start(['hi', 'hello', 'rent', 'car', 'مرحبا'], 'welcome');
        $this->buttons('welcome', 'Welcome', 'Hi {{contact.first_name}} 👋 Welcome to {{business.name}} 🚗 How can we help?',
            ['rent' => 'Rent a Car', 'fleet' => 'Our Fleet', 'talk' => 'Talk to us'], ['rent' => 'category', 'fleet' => 'category', 'talk' => 'handoff']);
        $rows = [];
        $to = [];
        foreach ($categories as $i => $category) {
            $id = 'cat'.($i + 1);
            $rows[] = ['id' => $id, 'title' => mb_substr($category, 0, 24)];
            $items = array_values(array_filter($cars, fn ($p) => mb_strtolower($p['category']) === mb_strtolower($category)));
            if ($items === []) {
                $to[$id] = 'pickup';
            } else {
                $to[$id] = "models_{$id}";
                $this->add("models_{$id}", 'product', $category, [
                    'mode' => 'cards', 'text' => "Our {$category} cars 🚗", 'order_label' => 'Book this car',
                    'items' => array_map(fn ($p) => array_diff_key($p, ['category' => true]), array_slice($items, 0, 10)),
                ], ['next' => 'pickup']);
            }
        }
        $this->add('category', 'list', 'Category', ['text' => 'Which kind of car? 🚘', 'button_label' => 'Categories', 'sections' => [['title' => 'Categories', 'rows' => $rows]]], $to);
        $this->question('pickup', 'Pick-up date', 'When would you like to pick up the car? e.g. 25/10/2026', 'var:pickup', 'future_date', 'Please send a future date, e.g. 25/10/2026.', 'return');
        $this->question('return', 'Return date', 'And the return date?', 'var:return', 'future_date', 'Please send a date after the pick-up date, e.g. 28/10/2026.', 'days');
        $this->add('days', 'calculate', 'Rental days', ['expression' => 'max(1, days_between({{var.pickup}}, {{var.return}}))', 'target' => 'var:days', 'decimals' => 0], ['next' => 'location']);
        $this->list('location', 'Pick-up location', 'Where would you like to pick it up? ({{var.days}} day rental)', 'Locations', self::lines($c['locations'], 10), 'age');
        $this->question('age', 'Driver age', 'How old is the driver?', 'var:age', 'number', 'Please send the age as a number, e.g. 30.', 'licence');
        $this->buttons('licence', 'Valid licence?', 'Does the driver have a valid driving licence (UAE or international)?', ['yes' => 'Yes', 'no' => 'No'], ['yes' => 'eligible', 'no' => 'requirements']);
        $this->condition('eligible', 'Eligible?', [['field' => 'var:age', 'op' => 'gte', 'value' => '{{business.min_age}}']], 'name', 'requirements');
        $this->handoff('requirements', 'To rent with us the driver must be at least {{business.min_age}} with a valid licence. A team member will help you with the options 🙂',
            'To rent with us the driver must be at least {{business.min_age}} with a valid licence. Our team will reply when we open.');
        $this->question('name', 'Your name', 'What is your full name?', 'name', 'text', 'Please type your name.', 'phone');
        $this->question('phone', 'Phone number', 'And the best phone number to reach you?', 'var:phone', 'phone', 'Please send a valid phone number, e.g. +971 50 123 4567.', 'lead');
        $this->tag('lead', 'Tag “Rental Lead”', ['Rental Lead'], 'store');
        $this->set('store', 'Store car + dates', 'var:rental', '{{var.category}} · {{var.pickup}} → {{var.return}} ({{var.days}} days) · {{var.location}}', 'deposit');
        $this->add('deposit', 'url', 'Deposit link', ['text' => 'Reserve your car by paying the deposit of {{business.deposit}} on our website 🔒', 'cta' => 'Pay deposit', 'url' => '{{business.booking_url}}'], ['next' => 'route']);
        $this->add('route', 'hours', 'Team available?', ['mode' => 'business'], ['open' => 'confirm', 'closed' => 'confirm']);
        $this->add('confirm', 'handoff', 'Confirm availability', [
            'text' => 'Thanks {{contact.first_name}}! Our team will confirm availability shortly 🚗',
            'offline_text' => 'Thanks {{contact.first_name}}! We are closed right now; our team will confirm availability first thing in the morning 🚗', 'note' => true,
        ], []);
        $this->handoff('handoff', 'Connecting you with our team 🚗 Someone will reply shortly.', 'Thanks! We are closed right now and will reply as soon as we open.');
    }

    private function menu(): void
    {
        $this->start(['hi', 'hello', 'menu'], 'welcome');
        $this->buttons('welcome', 'Welcome menu', 'Hi {{contact.first_name}} 👋 Welcome to {{business.name}}! How can we help you today?',
            ['services' => 'Our services', 'hours' => 'Opening hours', 'person' => 'Talk to a person'], ['services' => 'services', 'hours' => 'hours', 'person' => 'handoff']);
        $this->message('services', 'Our services', "Here is what we offer:\n• Service one\n• Service two\n• Service three", 'more');
        $this->message('hours', 'Opening hours', "We are open {{business.hours}}.\n📍 {{business.address}}", 'more');
        $this->buttons('more', 'Anything else?', 'Can we help with anything else?', ['menu' => 'Main menu', 'person' => 'Talk to a person', 'done' => 'No, thanks'],
            ['menu' => 'back', 'person' => 'handoff', 'done' => 'end']);
        $this->add('back', 'goto', 'Back to the menu', ['target' => 'welcome'], []);
        $this->handoff('handoff', 'Connecting you with our team 👩‍💼 Someone will reply shortly.', 'Thanks! Our team is away right now and will reply as soon as we are back.');
        $this->end('end', 'Goodbye', 'Thank you for contacting {{business.name}}! 😊');
    }

    private function lead(): void
    {
        $this->start([], 'ask_name');
        $this->question('ask_name', 'Ask the name', 'Welcome to {{business.name}}! 👋 What is your name?', 'name', 'text', 'Please type your name.', 'ask_email');
        $this->question('ask_email', 'Ask the email', 'Thanks {{contact.first_name}}! What is your email address?', 'email', 'email',
            'That does not look like an email address. Please type it again, e.g. name@example.com', 'tag');
        $this->tag('tag', 'Tag as lead', ['Lead'], 'handoff');
        $this->add('handoff', 'handoff', 'Talk to a person', ['text' => 'Thank you! A member of our team will contact you shortly.', 'assign' => 'auto'], []);
    }

    private function blank(): void
    {
        $this->start([], 'handoff');
        $this->add('handoff', 'handoff', 'Talk to a person', ['text' => 'Thanks for your message! A member of our team will reply shortly.', 'assign' => 'none'], []);
    }

    // ── Building blocks ──────────────────────────────────────────────────────────────────────

    /** @param list<string> $keywords */
    private function start(array $keywords, string $next): void
    {
        $this->add('start', 'start', 'Conversation starts', ['any_message' => true, 'keywords' => $keywords, 'keyword_match' => 'starts', 'ad_referral' => true], ['next' => $next]);
    }

    /**
     * @param  array<string, string>  $buttons  id → title
     * @param  array<string, string>  $to  output → node
     * @param  array<string, mixed>  $extra
     */
    private function buttons(string $id, string $title, string $text, array $buttons, array $to, array $extra = []): void
    {
        $this->add($id, 'buttons', $title, ['text' => $text, 'buttons' => array_map(fn ($k, $v) => ['id' => $k, 'title' => $v], array_keys($buttons), $buttons)] + $extra, $to);
    }

    /** @param list<string> $options every row leads to $next */
    private function list(string $id, string $title, string $text, string $button, array $options, string $next): void
    {
        $rows = [];
        $to = [];
        foreach (array_slice($options, 0, 10) as $i => $option) {
            $rid = 'r'.($i + 1);
            $rows[] = ['id' => $rid, 'title' => mb_substr($option, 0, 24)];
            $to[$rid] = $next;
        }
        $this->add($id, 'list', $title, ['text' => $text, 'button_label' => $button, 'sections' => [['title' => mb_substr($button, 0, 24), 'rows' => $rows]]], $to);
    }

    private function question(string $id, string $title, string $text, string $saveTo, string $validate, ?string $error, string $next): void
    {
        $this->add($id, 'question', $title, ['text' => $text, 'save_to' => $saveTo, 'validate' => $validate, 'error_text' => $error], ['next' => $next]);
    }

    private function message(string $id, string $title, string $text, string $next): void
    {
        $this->add($id, 'message', $title, ['text' => $text], ['next' => $next]);
    }

    /** @param list<string> $tags */
    private function tag(string $id, string $title, array $tags, string $next): void
    {
        $this->add($id, 'tag', $title, ['add' => $tags, 'remove' => [], 'assign' => 'none'], ['next' => $next]);
    }

    private function set(string $id, string $title, string $target, string $value, string $next): void
    {
        $this->add($id, 'setfield', $title, ['target' => $target, 'value' => $value], ['next' => $next]);
    }

    /** @param list<array{field: string, op: string, value: ?string}> $rules */
    private function condition(string $id, string $title, array $rules, string $yes, string $no): void
    {
        $this->add($id, 'condition', $title, ['match' => 'all', 'rules' => $rules], ['yes' => $yes, 'no' => $no]);
    }

    private function handoff(string $id, string $text, ?string $offline): void
    {
        $this->add($id, 'handoff', 'Talk to a person', ['text' => $text, 'offline_text' => $offline, 'assign' => 'none', 'note' => true], []);
    }

    private function end(string $id, string $title, ?string $text): void
    {
        $this->add($id, 'end', $title, ['text' => $text, 'action' => 'none'], []);
    }

    /**
     * Optional "POST the booking to your system" block before $next.
     *
     * @param  array<string, mixed>  $c
     */
    private function api(array $c, string $next): string
    {
        $url = is_string($c['booking_api_url'] ?? null) ? trim($c['booking_api_url']) : '';
        if (! str_starts_with($url, 'https://')) {
            return $next;
        }
        $this->add('api', 'http', 'Send to booking system', [
            'method' => 'POST', 'url' => $url,
            'body' => '{"name": "{{contact.name}}", "phone": "{{contact.phone}}", "service": "{{var.service}}{{var.department}}", "date": "{{var.date}}", "time": "{{var.time}}"}',
            'save' => [], 'timeout' => 5, 'retries' => 1,
        ], ['success' => $next, 'error' => $next]);

        return 'api';
    }

    /**
     * Optional reminder: wait until N hours before the slot, then an approved template.
     *
     * @param  array<string, mixed>  $c
     */
    private function reminder(array $c, string $dateVar, string $timeVar, string $next): string
    {
        $t = $c['reminder_template'] ?? null;
        if (! is_array($t) || ! is_string($t['name'] ?? null) || ! is_string($t['language'] ?? null)) {
            return $next;
        }
        $hours = max(1, min(168, (int) ($c['reminder_hours'] ?? 24)));
        $this->add('wait', 'delay', "Wait until {$hours}h before", ['mode' => 'until', 'until' => "{{{$dateVar}}} {{{$timeVar}}}", 'offset_minutes' => -60 * $hours], ['next' => 'reminder']);
        $this->add('reminder', 'template', 'Reminder', ['name' => $t['name'], 'language' => $t['language'], 'header' => [], 'body' => ["{{{$dateVar}}} {{{$timeVar}}}"]], ['next' => $next]);

        return 'wait';
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $to
     */
    private function add(string $id, string $type, string $title, array $config, array $to): void
    {
        $this->nodes[$id] = ['id' => $id, 'type' => $type, 'title' => $title, 'config' => $config, 'outputs' => $to];
    }

    /**
     * Left-to-right layout: each block one column right of the first block that leads to it; the
     * blocks of a column stacked in the order they were reached.
     *
     * @return list<array<string, mixed>>
     */
    private function layout(): array
    {
        $depth = ['start' => 0];
        $order = ['start'];
        $queue = ['start'];
        while ($queue !== []) {
            $id = array_shift($queue);
            $node = $this->nodes[$id] ?? null;
            if ($node === null) {
                continue;
            }
            $next = array_values($node['outputs']);
            if ($node['type'] === 'goto') {
                $next = [];
            }
            foreach ($next as $to) {
                if (isset($this->nodes[$to]) && ! isset($depth[$to])) {
                    $depth[$to] = $depth[$id] + 1;
                    $order[] = $to;
                    $queue[] = $to;
                }
            }
        }
        foreach (array_keys($this->nodes) as $id) {
            if (! isset($depth[$id])) {
                $depth[$id] = max($depth) + 1;
                $order[] = $id;
            }
        }
        $y = [];
        $out = [];
        foreach ($order as $id) {
            $node = $this->nodes[$id];
            $col = $depth[$id];
            // Card height as drawn by the builder: header, message preview, one row per branch.
            $height = 190 + 36 * max(1, count(FlowGraph::outputs($node['type'], $node['config'] + ['no_match' => 'handoff']))) + ($node['type'] === 'product' ? 120 : 0);
            $top = $y[$col] ?? 60;
            $y[$col] = $top + $height + 40;
            $out[] = [
                'id' => $id, 'type' => $node['type'], 'title' => $node['title'], 'auto_title' => false,
                'x' => 60 + $col * 410, 'y' => $top, 'config' => $node['config'],
                'outputs' => array_map(fn (string $out, string $target) => ['id' => $out, 'to' => $target], array_keys($node['outputs']), $node['outputs']),
            ];
        }

        return $out;
    }

    // ── Form values ──────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private static function lines(mixed $value, int $max): array
    {
        $items = is_array($value) ? $value : preg_split('/\r?\n/', (string) $value);
        $out = [];
        foreach ((array) $items as $item) {
            $s = trim(mb_substr(is_scalar($item) ? (string) $item : '', 0, 24));
            if ($s !== '' && ! in_array(mb_strtolower($s), array_map('mb_strtolower', $out), true)) {
                $out[] = $s;
            }
        }

        return array_slice($out ?: ['Option'], 0, $max);
    }

    /** @return array<string, float> */
    private static function prices(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $k => $v) {
            if (is_array($v)) {
                [$k, $v] = [$v['name'] ?? $v[0] ?? '', $v['value'] ?? $v[1] ?? 0];
            }
            $k = trim(mb_substr((string) $k, 0, 24));
            if ($k !== '' && is_numeric($v)) {
                $out[$k] = (float) $v;
            }
        }

        return $out ?: ['Standard' => 1.0];
    }

    /** @return list<array{id: string, category: string, name: string, price: ?string, description: ?string, image_url: ?string, link: ?string}> */
    private static function products(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? array_slice($value, 0, 80) : [] as $i => $p) {
            if (! is_array($p) || trim((string) ($p['name'] ?? '')) === '') {
                continue;
            }
            $https = fn ($u) => is_string($u) && str_starts_with(trim($u), 'https://') ? trim($u) : null;
            $out[] = [
                'id' => 'p'.($i + 1),
                'category' => trim((string) ($p['category'] ?? '')),
                'name' => mb_substr(trim((string) $p['name']), 0, 60),
                'price' => isset($p['price']) && trim((string) $p['price']) !== '' ? mb_substr(trim((string) $p['price']), 0, 30) : null,
                'description' => isset($p['description']) && trim((string) $p['description']) !== '' ? mb_substr(trim((string) $p['description']), 0, 300) : null,
                'image_url' => $https($p['image_url'] ?? null),
                'link' => $https($p['link'] ?? null) ?? (is_string($p['link'] ?? null) && str_starts_with($p['link'], '{{') ? $p['link'] : null),
            ];
        }

        return $out;
    }

    private static function clock(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : $default;
    }

    /** @return list<array<string, string>> */
    private static function sampleProducts(): array
    {
        $img = fn (string $t) => 'https://placehold.co/800x600/png?text='.rawurlencode($t);
        $rows = [
            ['Birthday', 'Chocolate Fudge Cake', 'From AED 140', 'chocolate-fudge-cake'],
            ['Birthday', 'Rainbow Sprinkle Cake', 'From AED 160', 'rainbow-sprinkle-cake'],
            ['Birthday', 'Red Velvet Cake', 'From AED 150', 'red-velvet-cake'],
            ['Vintage', 'Vintage Heart Cake', 'From AED 220', 'vintage-heart-cake'],
            ['Vintage', 'Lambeth Piped Cake', 'From AED 260', 'lambeth-cake'],
            ['Kids', 'Unicorn Cake', 'From AED 240', 'unicorn-cake'],
            ['Kids', 'Dinosaur Cake', 'From AED 230', 'dinosaur-cake'],
            ['Photo', 'Photo Print Cake', 'From AED 180', 'photo-cake'],
            ['Wedding', 'Two-tier Floral Cake', 'From AED 950', 'two-tier-floral'],
            ['Cupcakes', 'Box of 12 Cupcakes', 'From AED 120', 'cupcakes-12'],
        ];

        return array_map(fn ($r) => ['category' => $r[0], 'name' => $r[1], 'price' => $r[2], 'image_url' => $img($r[1]), 'link' => '{{business.store_url}}/product/'.$r[3]], $rows);
    }

    /** @return list<array<string, string>> */
    private static function sampleCars(): array
    {
        $img = fn (string $t) => 'https://placehold.co/800x600/png?text='.rawurlencode($t);
        $rows = [
            ['Economy', 'Nissan Sunny', 'From AED 89/day'], ['Economy', 'Kia Picanto', 'From AED 79/day'],
            ['Sedan', 'Toyota Camry', 'From AED 149/day'], ['SUV', 'Nissan Patrol', 'From AED 449/day'], ['SUV', 'Toyota RAV4', 'From AED 199/day'],
            ['Luxury', 'Mercedes S-Class', 'From AED 899/day'], ['Sports', 'Porsche 911', 'From AED 1,499/day'],
        ];

        return array_map(fn ($r) => ['category' => $r[0], 'name' => $r[1], 'price' => $r[2], 'image_url' => $img($r[1]), 'link' => '{{business.booking_url}}'], $rows);
    }
}
