import 'package:flutter/material.dart';
import 'package:hubby_global/shared/theme/app_theme.dart';
import 'package:lucide_icons/lucide_icons.dart';

class StoresScreen extends StatelessWidget {
  const StoresScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Stores'),
        actions: [
          IconButton(onPressed: () {}, icon: const Icon(LucideIcons.plus, size: 20)),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          children: [
            _buildMasterStoreInfo(),
            const SizedBox(height: 24),
            ListView.separated(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              itemCount: 3,
              separatorBuilder: (_, __) => const SizedBox(height: 16),
              itemBuilder: (context, index) {
                return _buildStoreCard(index);
              },
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildMasterStoreInfo() {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppTheme.primaryColor.withOpacity(0.1),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppTheme.primaryColor.withOpacity(0.3)),
      ),
      child: const Row(
        children: [
          Icon(LucideIcons.crown, color: AppTheme.primaryColor, size: 32),
          SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Master Store Sync', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                SizedBox(height: 4),
                Text(
                  'The master store serves as the central inventory source for all your channels.',
                  style: TextStyle(color: AppTheme.mutedColor, fontSize: 12),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildStoreCard(int index) {
    final isMaster = index == 0;
    final platforms = ['Shopify', 'Salla', 'WooCommerce'];
    final platform = platforms[index];

    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: AppTheme.cardColor,
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: isMaster ? AppTheme.primaryColor : AppTheme.borderColor),
        boxShadow: isMaster ? [BoxShadow(color: AppTheme.primaryColor.withOpacity(0.1), blurRadius: 20, offset: const Offset(0, 10))] : null,
      ),
      child: Column(
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AppTheme.backgroundColor,
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Icon(
                  platform == 'Shopify' ? LucideIcons.shoppingBag : LucideIcons.globe,
                  color: platform == 'Shopify' ? Colors.green : AppTheme.primaryColor,
                  size: 24,
                ),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      platform == 'Shopify' ? 'Main Shopify Store' : 'Channel #$index',
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                    ),
                    const SizedBox(height: 2),
                    Row(
                      children: [
                        const Icon(LucideIcons.checkCircle, color: AppTheme.secondaryColor, size: 12),
                        const SizedBox(width: 4),
                        const Text('Connected', style: TextStyle(color: AppTheme.mutedColor, fontSize: 12)),
                      ],
                    ),
                  ],
                ),
              ),
              if (isMaster) const Icon(LucideIcons.crown, color: AppTheme.primaryColor, size: 20),
            ],
          ),
          const SizedBox(height: 20),
          Row(
            children: [
              Expanded(
                child: OutlinedButton(
                  onPressed: () {},
                  style: OutlinedButton.styleFrom(
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    side: const BorderSide(color: AppTheme.borderColor),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                  child: Text(
                    isMaster ? 'Master Store' : 'Set as Master',
                    style: TextStyle(
                      color: isMaster ? AppTheme.primaryColor : AppTheme.foregroundColor,
                      fontSize: 12,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              IconButton(
                onPressed: () {},
                icon: const Icon(LucideIcons.refreshCw, size: 18),
                style: IconButton.styleFrom(
                  backgroundColor: AppTheme.backgroundColor,
                  padding: const EdgeInsets.all(12),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
