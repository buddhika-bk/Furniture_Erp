export const registry = [
  {
    "id": "sales",
    "name": "Sales and POS",
    "description": "Serve customers. Keep every sale connected.",
    "icon": "bag",
    "color": "#5d6ee9",
    "routes": [
      [
        "pos",
        "Point of sale",
        "sales.create"
      ],
      [
        "invoices",
        "Invoices",
        "sales.view"
      ],
      [
        "cashier_sessions",
        "Daily closing",
        "sales.view"
      ],
      [
        "discount_rules",
        "Discount rules",
        "sales.view"
      ]
    ]
  },
  {
    "id": "manufacturing",
    "name": "Manufacturing",
    "description": "From recipe to finished product.",
    "icon": "factory",
    "color": "#d38b36",
    "routes": [
      [
        "production_orders",
        "Production orders",
        "manufacturing.view"
      ],
      [
        "boms",
        "Recipes (BOM)",
        "manufacturing.view"
      ]
    ]
  },
  {
    "id": "inventory",
    "name": "Inventory",
    "description": "Every product. Every location.",
    "icon": "box",
    "color": "#3d9d8a",
    "routes": [
      [
        "inventory",
        "Stock by location",
        "inventory.view"
      ],
      [
        "stock_movements",
        "Stock ledger",
        "inventory.view"
      ],
      [
        "stock_transfers",
        "Transfers",
        "inventory.view"
      ],
      [
        "stock_adjustments",
        "Adjustments",
        "inventory.view"
      ],
      [
        "products",
        "Products",
        "products.view"
      ],
      [
        "categories",
        "Categories",
        "products.view"
      ],
      [
        "units",
        "Units",
        "products.view"
      ],
      [
        "warehouses",
        "Warehouses",
        "inventory.view"
      ]
    ]
  },
  {
    "id": "costing",
    "name": "Product Costing",
    "description": "Understand what every product costs.",
    "icon": "calculator",
    "color": "#ae78c3",
    "routes": [
      [
        "product_costs",
        "Product costs",
        "manufacturing.view"
      ]
    ]
  },
  {
    "id": "crm",
    "name": "CRM and Loyalty",
    "description": "Build lasting customer relationships.",
    "icon": "users",
    "color": "#d57496",
    "routes": [
      [
        "customers",
        "Customers",
        "crm.view"
      ],
      [
        "loyalty_tiers",
        "Loyalty tiers",
        "crm.view"
      ],
      [
        "loyalty_rewards",
        "Loyalty rewards",
        "crm.view"
      ]
    ]
  },
  {
    "id": "hr",
    "name": "HR and Payroll",
    "description": "Take care of your people.",
    "icon": "briefcase",
    "color": "#7188bd",
    "routes": [
      [
        "employees",
        "Employees",
        "hr.view"
      ],
      [
        "attendance",
        "Attendance",
        "hr.view"
      ],
      [
        "leaves",
        "Leave",
        "hr.view"
      ],
      [
        "payroll_periods",
        "Payroll",
        "payroll.view"
      ],
      [
        "commissions",
        "Commissions",
        "payroll.view"
      ],
      [
        "commission_rules",
        "Commission rules",
        "payroll.view"
      ],
      [
        "departments",
        "Departments",
        "hr.view"
      ],
      [
        "positions",
        "Positions",
        "hr.view"
      ]
    ]
  },
  {
    "id": "finance",
    "name": "Finance",
    "description": "A clear view of your money.",
    "icon": "wallet",
    "color": "#488cbd",
    "routes": [
      [
        "expenses",
        "Expenses",
        "finance.view"
      ],
      [
        "payments",
        "Payments",
        "finance.view"
      ],
      [
        "financial_transactions",
        "Cash & bank ledger",
        "finance.view"
      ],
      [
        "accounts",
        "Accounts",
        "finance.view"
      ],
      [
        "expense_categories",
        "Expense categories",
        "finance.view"
      ]
    ]
  },
  {
    "id": "purchasing",
    "name": "Purchasing",
    "description": "Keep your supply chain moving.",
    "icon": "cart",
    "color": "#b79455",
    "routes": [
      [
        "purchase_orders",
        "Purchase orders",
        "purchasing.view"
      ],
      [
        "supplier_invoices",
        "Supplier invoices",
        "purchasing.view"
      ],
      [
        "suppliers",
        "Suppliers",
        "purchasing.view"
      ]
    ]
  },
  {
    "id": "delivery",
    "name": "Delivery and Fleet",
    "description": "Deliver on your promises.",
    "icon": "truck",
    "color": "#5aa4b7",
    "routes": [
      [
        "delivery_orders",
        "Deliveries",
        "delivery.view"
      ],
      [
        "vehicles",
        "Vehicles",
        "delivery.view"
      ],
      [
        "vehicle_logs",
        "Fuel & maintenance",
        "delivery.view"
      ]
    ]
  },
  {
    "id": "marketing",
    "name": "Marketing",
    "description": "Make every campaign count.",
    "icon": "megaphone",
    "color": "#ba7c64",
    "routes": [
      [
        "campaigns",
        "Campaigns",
        "marketing.view"
      ],
      [
        "campaign_expenses",
        "Campaign spend",
        "marketing.view"
      ]
    ]
  },
  {
    "id": "reports",
    "name": "Reports",
    "description": "Turn business data into decisions.",
    "icon": "chart",
    "color": "#7a79c8",
    "routes": [
      [
        "reports",
        "Reports",
        "reports.view"
      ],
      [
        "dashboard",
        "Dashboard",
        "dashboard.view"
      ]
    ]
  },
  {
    "id": "admin",
    "name": "Administration",
    "description": "Your team, access and preferences.",
    "icon": "settings",
    "color": "#738497",
    "routes": [
      [
        "branches",
        "Branches",
        "branches.view"
      ],
      [
        "users",
        "Users",
        "users.view"
      ],
      [
        "roles",
        "Roles & permissions",
        "users.view"
      ],
      [
        "settings",
        "Settings",
        "settings.view"
      ],
      [
        "audit_logs",
        "Audit log",
        "audit.view"
      ],
      [
        "alerts",
        "Alerts",
        "dashboard.view"
      ]
    ]
  }
];
